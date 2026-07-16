<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Models;

use FancyFlow\Laravel\Jobs\RunWorkflowJob;
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
 */
class WorkflowRun extends Model
{
    public const PENDING = 'pending';
    public const RUNNING = 'running';
    public const AWAITING_APPROVAL = 'awaiting_approval';
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
