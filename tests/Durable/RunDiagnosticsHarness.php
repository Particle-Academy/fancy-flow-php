<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Durable;

use FancyFlow\Laravel\Events\WorkflowLog;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Models\WorkflowRun;
use Illuminate\Support\Facades\Event;
use Throwable;

/**
 * Runs a `flow/run-diagnostics` row through whichever queue driver the test
 * case pins, and reports the warnings a HOST received.
 *
 * The in-process table (`tests/Parity/RunDiagnosticsConformanceTest.php`) reads
 * the engine's events straight off `FlowRunner`. A Laravel app never does: it
 * dispatches a run and listens for events. So this reads what arrived as
 * {@see WorkflowLog}, which is the only thing that counts as delivered.
 */
final class RunDiagnosticsHarness
{
    /**
     * @param  array<string,mixed>  $case
     * @return list<array{nodeId: ?string, message: string, detail: mixed}>
     */
    public static function run(array $case): array
    {
        // The caller fakes WorkflowLog ONCE per test, before the first run. The
        // manager is a singleton holding the dispatcher it was built with, so a
        // fake installed per case would be one the manager never sees -- every
        // row after the first would read an empty fake and "pass" or fail for
        // a reason unrelated to the driver.
        try {
            $run = FancyFlow::dispatch($case['input']['schema'], $case['input']['initialInputs'] ?? []);
        } catch (Throwable) {
            // The sync queue surfaces a throw a worker would retry; the run row
            // is settled either way, and the question here is what was said.
            $run = WorkflowRun::query()->latest('id')->first();
        }

        $warnings = [];
        foreach (Event::dispatched(WorkflowLog::class) as [$event]) {
            if ($event->runId === $run->run_key && $event->level === 'warn') {
                $warnings[] = ['nodeId' => $event->nodeId, 'message' => $event->message, 'detail' => $event->detail];
            }
        }

        // Sorted, as the table specifies: runtimes and drivers may reach
        // same-depth nodes in different orders without either being wrong.
        usort($warnings, static fn (array $a, array $b): int => strcmp($a['message'], $b['message']));

        return $warnings;
    }
}
