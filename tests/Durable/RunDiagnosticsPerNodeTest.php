<?php

declare(strict_types=1);

use FancyFlow\Laravel\Events\WorkflowLog;
use FancyFlow\Tests\Durable\RunDiagnosticsHarness;
use Illuminate\Support\Facades\Event;
use ParticleAcademy\Conformance\Conformance;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

/**
 * The run-diagnostics table, through the `per_node` driver -- the default, and
 * the one a Laravel app actually runs workflows on.
 *
 * Two gaps made every row that warns fail here while the in-process table
 * passed:
 *
 *  1. The manager's event bridge dispatched no Laravel event for a `log` at all,
 *     so neither warning reached a host on either driver.
 *  2. Each node job replays the graph and forwards only its own node's events.
 *     An undelivered edge whose target is SKIPPED is noticed while some other
 *     node's job replays -- and a skipped node never gets a job of its own -- so
 *     that warning was emitted and filtered out on every run. It is now emitted
 *     once, when `AdvanceWorkflowJob` records the skip.
 *
 * Same table, same goldens as the in-process run: the driver must not change
 * what a host is told.
 */
it('delivers the flow/run-diagnostics warnings on the per_node driver', function (): void {
    Event::fake([WorkflowLog::class]);

    $summary = Conformance::runTable('flow/run-diagnostics', RunDiagnosticsHarness::run(...));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter($summary['results'] ?? [], static fn (array $r): bool => ($r['status'] ?? '') === 'fail');

    expect($failures)->toBe([], 'per_node disagrees with the shared table on: '.implode(', ', array_column($failures, 'id')));
    expect($summary['passed'])->toBeGreaterThan(12);
});
