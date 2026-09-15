<?php

declare(strict_types=1);

use FancyFlow\Laravel\Events\WorkflowLog;
use FancyFlow\Tests\Durable\RunDiagnosticsHarness;
use Illuminate\Support\Facades\Event;
use ParticleAcademy\Conformance\Conformance;

uses(\FancyFlow\Tests\Durable\DurableTestCase::class);

/**
 * The run-diagnostics table, through the `single` driver.
 *
 * One job runs the whole graph, so nothing is filtered -- but the manager's
 * event bridge dispatched no Laravel event for a `log`, so a host listening on
 * this driver received none of these warnings either.
 */
it('delivers the flow/run-diagnostics warnings on the single driver', function (): void {
    Event::fake([WorkflowLog::class]);

    $summary = Conformance::runTable('flow/run-diagnostics', RunDiagnosticsHarness::run(...));

    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter($summary['results'] ?? [], static fn (array $r): bool => ($r['status'] ?? '') === 'fail');

    expect($failures)->toBe([], 'the single driver disagrees with the shared table on: '.implode(', ', array_column($failures, 'id')));
    expect($summary['passed'])->toBeGreaterThan(12);
});
