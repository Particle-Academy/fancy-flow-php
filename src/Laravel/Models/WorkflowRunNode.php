<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One node of one run — the claim, and that node's checkpoint.
 *
 * Under the `per_node` queue driver a run is not one job but a job per node,
 * and this row is how those jobs agree on who runs what. `(run_key, node_id)`
 * is unique, so claiming is an insert: whoever inserts owns the node, and the
 * loser gets a conflict rather than a second execution. See
 * {@see \FancyFlow\Laravel\Runs\NodeClaims}.
 *
 * It is also the durable checkpoint the whole change exists for. `output` and
 * `ports` are written in the same statement as the transition to `completed`,
 * so a worker killed mid-run loses at most the node that was in flight — where
 * the single-job driver lost every node completed since the last whole-graph
 * write.
 *
 * @property string                  $run_key
 * @property string                  $node_id
 * @property string                  $status   claimed | completed | skipped | paused | failed
 * @property string|null             $owner
 * @property array<string,mixed>|null $inputs
 * @property mixed                   $output
 * @property list<string>|null       $ports
 * @property string|null             $error
 * @property int                     $attempts
 */
class WorkflowRunNode extends Model
{
    /** Held by a worker, not yet settled. */
    public const CLAIMED = 'claimed';

    /** Executed; `output` + `ports` are the checkpoint. */
    public const COMPLETED = 'completed';

    /**
     * Never executed, correctly: every incoming edge was dead, or the node is a
     * `note` annotation. Distinct from `completed` because a skipped node
     * publishes NOTHING — feeding it back as a resume output would invent a
     * value the engine never produced.
     */
    public const SKIPPED = 'skipped';

    /** Halted for a person. Cleared when the run is resumed with a decision. */
    public const PAUSED = 'paused';

    /** Attempts exhausted. The run fails with this node's error. */
    public const FAILED = 'failed';

    /** Settled for good — neither re-claimable nor blocking. */
    public const SETTLED = [self::COMPLETED, self::SKIPPED];

    protected $guarded = [];

    protected $casts = [
        'inputs' => 'array',
        'output' => 'array',
        'ports' => 'array',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_run_nodes';
        parent::__construct($attributes);
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(WorkflowRun::class, 'run_key', 'run_key');
    }

    /** True when this node will never run again in this run. */
    public function isSettled(): bool
    {
        return in_array($this->status, self::SETTLED, true);
    }

    /**
     * When this node was FIRST attempted — the clock an idempotency window is
     * measured from.
     *
     * `created_at` rather than `claimed_at`, and the difference is the whole
     * point: `claimed_at` is refreshed on every reclaim, so it moves with the
     * retry and would report a 25-hour-old first attempt as seconds ago. The
     * row is inserted once per logical attempt sequence and only ever updated
     * after that, so `created_at` is exactly "when attempt 1 began".
     *
     * A resumed human gate is the deliberate exception: {@see NodeClaims::clearPaused()}
     * DELETES the paused row, so the node's re-execution genuinely is a first
     * attempt and gets a fresh clock. That is correct — nothing was sent to a
     * provider while the run was parked.
     *
     * `FancyFlow\Tests\Durable\RunIdentityPerNodeTest` pins both halves.
     */
    public function firstAttemptAt(): string
    {
        $at = $this->created_at ?? $this->claimed_at;

        if (! $at instanceof \DateTimeInterface) {
            return (string) $at;
        }

        // Normalised to UTC before the `Z` is appended. An app on a local
        // timezone would otherwise stamp a local time with a UTC marker, and
        // the retry window would be read hours out — in whichever direction
        // makes a double charge more likely.
        return \DateTimeImmutable::createFromInterface($at)
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }
}
