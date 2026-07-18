<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Models;

use FancyFlow\Laravel\Jobs\RunWorkflowJob;
use FancyFlow\NodeKindRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One execution of a workflow. Self-contained (stores its own schema + inputs)
 * so it is replayable, and carries the `node_outputs` checkpoint the queued
 * {@see RunWorkflowJob} uses to resume from the last completed node.
 *
 * @property array<string,mixed>      $schema
 * @property array<string,mixed>|null $initial_inputs
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
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';

    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'initial_inputs' => 'array',
        'node_outputs' => 'array',
        'outputs' => 'array',
        'events' => 'array',
        'approvals' => 'array',
        'submissions' => 'array',
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

    /**
     * Submit the paused `user_input` node's form values and resume the run.
     * The values are emitted on the node's `out` port when the run continues.
     *
     * @param  array<string,mixed>  $values
     */
    public function submitInput(?string $nodeId = null, array $values = []): static
    {
        $node = $nodeId ?? (string) $this->awaiting_node;

        $submissions = $this->submissions ?? [];
        $submissions[$node] = $values;

        $this->forceFill([
            'submissions' => $submissions,
            'status' => self::PENDING,
            'awaiting_node' => null,
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
        return $this->decide($nodeId ?? (string) $this->awaiting_node, true);
    }

    /** Deny the paused node and resume the run (routes down the `denied` branch). */
    public function deny(?string $nodeId = null): static
    {
        return $this->decide($nodeId ?? (string) $this->awaiting_node, false);
    }

    private function decide(string $nodeId, bool $approved): static
    {
        $approvals = $this->approvals ?? [];
        $approvals[$nodeId] = $approved;
        $this->forceFill([
            'approvals' => $approvals,
            'status' => self::PENDING,
            'awaiting_node' => null,
        ])->save();

        RunWorkflowJob::enqueue($this);

        return $this;
    }
}
