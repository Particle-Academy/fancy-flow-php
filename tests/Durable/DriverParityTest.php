<?php

declare(strict_types=1);

use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Models\WorkflowRun;

uses(\FancyFlow\Tests\Durable\DriverParityTestCase::class);

/**
 * The per-node driver against the golden parity fixtures.
 *
 * `tests/Parity` proves the ENGINE reproduces fancy-flow's TypeScript runtime,
 * fixture for fixture. That guarantee is what the `per_node` driver is most
 * capable of quietly breaking: it splits a run across jobs, rebuilds each node's
 * inputs from stored outputs, and decides for itself which branches are live. A
 * driver that got any of that subtly wrong would still complete runs, still
 * report success, and produce different answers.
 *
 * So the fixtures are run again — same JSON, same golden outputs — through the
 * whole queue driver rather than through `FlowRunner` directly. Same schema in,
 * same outputs out, however many jobs it took.
 *
 * Two fixtures are excluded, and not because they are inconvenient: the durable
 * layer deliberately swaps `user_input` and `human_approval` for executors that
 * PARK the run rather than passing empty values on. Those runs correctly do not
 * finish, on either driver. Their behaviour is pinned in `PerNodeRunTest`
 * instead.
 */
const PARITY_HUMAN_PAUSES = ['17-user-input', '18-human-approval'];

$files = array_values(array_filter(
    glob(__DIR__.'/../Parity/fixtures/*.json') ?: [],
    static fn (string $file) => ! in_array(basename($file, '.json'), PARITY_HUMAN_PAUSES, true),
));

it('has fixtures to run against', function () use ($files) {
    expect(count($files))->toBeGreaterThanOrEqual(21);
});

foreach ($files as $file) {
    $name = basename($file, '.json');

    it("reproduces the golden result for fixture {$name}, one job per node", function () use ($file) {
        $doc = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);

        $run = null;
        try {
            $run = FancyFlow::dispatch($doc['schema'], $doc['initialInputs'] ?? []);
        } catch (Throwable) {
            // The sync queue surfaces the throw a real worker would turn into a
            // retry. The run row is still settled by failed().
            $run = WorkflowRun::query()->latest('id')->first();
        }

        $run->refresh();

        if ($doc['expected']['ok'] === true) {
            expect($run->status)->toBe(WorkflowRun::COMPLETED);
        } else {
            expect($run->status)->toBe(WorkflowRun::FAILED);
        }

        if (isset($doc['expected']['errorContains'])) {
            expect($run->error)->toContain($doc['expected']['errorContains']);
        }

        if (array_key_exists('outputs', $doc['expected'])) {
            $expected = $doc['expected']['outputs'];
            $actual = $run->outputs ?? [];
            ksort($expected);
            ksort($actual);

            // Key ORDER legitimately differs — the engine collects in
            // topological order, the driver in the order nodes settled, which
            // under fan-out is not the same thing and is not meant to be. The
            // contract is the mapping, not the ordering.
            expect($actual)->toEqual($expected);
        }
    });
}
