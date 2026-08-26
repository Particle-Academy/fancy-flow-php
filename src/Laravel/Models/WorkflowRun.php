<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Models;

use FancyFlow\Exceptions\NotAwaitingHuman;
use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\NodeKindRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One execution of a workflow. Self-contained (stores its own schema + inputs)
 * so it is replayable, and carries the `node_outputs` checkpoint the queued
 * {@see RunWorkflowJob} uses to resume from the last completed node.
 *
 * @property array<string,mixed>      $schema
 * @property array<string,mixed>|null $initial_inputs
 * @property list<string>|null        $entry_nodes
 * @property array<string,mixed>|null $props
 * @property int|null                 $max_concurrent
 * @property array<string,mixed>|null $node_outputs
 * @property array<string,mixed>|null $outputs
 * @property array<string,bool>|null  $approvals
 * @property array<string,mixed>|null $submissions
 */
class WorkflowRun extends Model
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const AWAITING_APPROVAL = 'awaiting_approval';
    public const AWAITING_INPUT = 'awaiting_input';
    /**
     * A run parked on a wait this package does not define — a marketplace
     * node's own (`signature`, `payment`, …). `awaiting_kind` carries which.
     *
     * Approval and input keep their dedicated statuses rather than folding into
     * this one, because hosts already query on them.
     */
    public const AWAITING_HUMAN = 'awaiting_human';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    /**
     * Never started, deliberately. A cohort run whose {@see TriggerGuard} did
     * not pass — the state it was queued to act on changed underneath it, most
     * often because an earlier run in the same cohort deleted it.
     *
     * Distinct from FAILED: nothing went wrong, and `skipped_reason` says what
     * changed. Distinct from silently completing, which is what happened before
     * cohorts and is exactly the outcome that looks like success.
     */
    public const SKIPPED = 'skipped';

    /** Cohort collision policies. */
    public const POLICY_SERIAL_GUARDED = 'serial-guarded';
    public const POLICY_SERIAL = 'serial';
    public const POLICY_PARALLEL = 'parallel';

    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'initial_inputs' => 'array',
        'entry_nodes' => 'array',
        'props' => 'array',
        'guard' => 'array',
        'cohort_seq' => 'integer',
        'node_outputs' => 'array',
        'outputs' => 'array',
        'events' => 'array',
        'approvals' => 'array',
        'submissions' => 'array',
        'awaiting_detail' => 'array',
        'attempts' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_runs';
        parent::__construct($attributes);
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /**
     * The per-node claims + checkpoints, under the `per_node` queue driver.
     *
     * Empty under `single`, which keeps its whole checkpoint in `node_outputs`.
     * Where rows exist they are the authority: `node_outputs` is derived from
     * them so existing host code keeps reading what it always read.
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(WorkflowRunNode::class, 'run_key', 'run_key');
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED], true);
    }

    public function isAwaitingApproval(): bool
    {
        return $this->status === self::AWAITING_APPROVAL;
    }

    public function isAwaitingInput(): bool
    {
        return $this->status === self::AWAITING_INPUT;
    }

    /** Waiting on a person, whichever flavour — including a third-party wait. */
    public function isAwaitingHuman(): bool
    {
        return in_array(
            $this->status,
            [self::AWAITING_APPROVAL, self::AWAITING_INPUT, self::AWAITING_HUMAN],
            true,
        );
    }

    /**
     * What this run is waiting for — `approval`, `input`, or a node's own.
     *
     * Falls back to inferring from `status` so runs that parked BEFORE the
     * pause contract (and therefore have no `awaiting_kind`) still answer
     * correctly rather than returning null and looking un-paused.
     */
    public function awaitingKind(): ?string
    {
        if (is_string($this->awaiting_kind) && $this->awaiting_kind !== '') {
            return $this->awaiting_kind;
        }

        return match ($this->status) {
            self::AWAITING_APPROVAL => 'approval',
            self::AWAITING_INPUT => 'input',
            default => null,
        };
    }

    /**
     * Resume a run parked on a third-party wait.
     *
     * The submitted payload is delivered exactly the way `user_input` receives
     * its form values, so a marketplace node reads `$ctx->inputs['values']`
     * with no bespoke resume plumbing.
     */
    public function submitHuman(?string $nodeId = null, mixed $payload = null): static
    {
        return $this->submitInput($nodeId, is_array($payload) ? $payload : ['value' => $payload]);
    }

    /**
     * Submit the paused `user_input` node's form values and resume the run.
     * The values are emitted on the node's `out` port when the run continues.
     *
     * @param  array<string,mixed>  $values
     */
    public function submitInput(?string $nodeId = null, array $values = []): static
    {
        $node = $this->assertParkedOn($nodeId);

        $submissions = $this->submissions ?? [];
        $submissions[$node] = $values;

        $this->forceFill([
            'submissions' => $submissions,
            'status' => self::PENDING,
            'awaiting_node' => null,
            'awaiting_kind' => null,
            'awaiting_detail' => null,
        ])->save();

        RunWorkflowJob::enqueue($this);

        return $this;
    }

    /**
     * The form this run is paused on, so a host UI can render it: the paused
     * `user_input` node's configured title + fields, with the kind's
     * configSchema-declared defaults filled in for anything the node omits.
     *
     * Null when the run is not awaiting input (or the node has gone missing).
     *
     * @return array{nodeId:string,title:mixed,fields:mixed}|null
     */
    public function awaitingForm(): ?array
    {
        if (! $this->isAwaitingInput() || $this->awaiting_node === null) {
            return null;
        }

        $node = null;
        foreach ($this->schema['graph']['nodes'] ?? [] as $candidate) {
            if (($candidate['id'] ?? null) === $this->awaiting_node) {
                $node = $candidate;
                break;
            }
        }

        if ($node === null) {
            return null;
        }

        $registry = app(NodeKindRegistry::class);
        $kind = $registry->get((string) ($node['kind'] ?? ''));
        $config = array_merge(
            $kind !== null ? $registry->defaultConfigFor($kind) : [],
            $node['config'] ?? [],
        );

        return [
            'nodeId' => (string) $this->awaiting_node,
            'title' => $config['title'] ?? null,
            'fields' => $config['fields'] ?? [],
        ];
    }

    /** Approve the paused node and resume the run. */
    public function approve(?string $nodeId = null): static
    {
        return $this->decide($nodeId, true);
    }

    /** Deny the paused node and resume the run (routes down the `denied` branch). */
    public function deny(?string $nodeId = null): static
    {
        return $this->decide($nodeId, false);
    }

    /**
     * The node this run is parked on, or a loud failure.
     *
     * A human answer is an answer to a question the run actually asked. Nothing
     * used to check that: an answer could be recorded for any node at any time,
     * and because a recorded answer is what resumes a human node, one stored
     * before the node ran would resume a step that had never paused. On the
     * per_node driver a host frontend could win that race against the queued
     * run, skipping the gate entirely.
     *
     * Throwing beats ignoring. A submission that silently vanishes is the
     * mirror-image bug — a person fills the form and nothing happens.
     */
    private function assertParkedOn(?string $nodeId): string
    {
        $awaiting = $this->awaiting_node === null ? '' : (string) $this->awaiting_node;
        $target = $nodeId ?? $awaiting;

        if (! $this->isAwaitingHuman() || $target === '' || $target !== $awaiting) {
            throw NotAwaitingHuman::for(
                (string) $this->run_key,
                $nodeId,
                (string) $this->status,
                $this->awaiting_node === null ? null : (string) $this->awaiting_node,
            );
        }

        return $target;
    }

    private function decide(?string $nodeId, bool $approved): static
    {
        $nodeId = $this->assertParkedOn($nodeId);

        $approvals = $this->approvals ?? [];
        $approvals[$nodeId] = $approved;
        $this->forceFill([
            'approvals' => $approvals,
            'status' => self::PENDING,
            'awaiting_node' => null,
            'awaiting_kind' => null,
            'awaiting_detail' => null,
        ])->save();

        RunWorkflowJob::enqueue($this);

        return $this;
    }
}
