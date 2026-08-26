<?php

declare(strict_types=1);

use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Runtime\Port;
use FancyFlow\Workflow;

/**
 * A branching node whose payload is NULL must pass null on, not the wrapper.
 *
 * `activatedPorts` unwrapped the two port sugars asymmetrically:
 *
 *     Port::only   ['__port' => …]  ->  $result['value'] ?? null
 *     Port::branch ['branch' => …]  ->  $result['value'] ?? $result
 *
 * `??` cannot tell "there is no `value` key" from "`value` is present and
 * null". So `Port::branch($port, null)` fell through to `?? $result` and every
 * downstream node received **`['branch' => 'x', 'value' => null]`** — the
 * wrapper itself, with two fields nothing declared.
 *
 * The reachable path is the one that matters: `$ctx->input('in', $ctx->inputs)`
 * returns null when `in` is bound to an explicit null, which is exactly what an
 * upstream `transform` produces when its dot-path does not resolve. So a run
 * where a transform had already quietly resolved to nothing ALSO started
 * emitting a shape no kind declares — and `{{ in.branch }}` / `{{ in.value }}`
 * silently became resolvable while the real fields were not.
 *
 * `switch_case` never had it: `?? null` yields null rather than the wrapper.
 * Two sugars documented as equivalent, differing on the one input where it
 * counts.
 *
 * Found by the reference consumer reviewing the `emits` relation assignments —
 * checking the CONSUMER of the return, not just the return.
 */
function runBranchWith(mixed $payload): mixed
{
    $graph = Workflow::import([
        '$schema' => 'https://particle.academy/schemas/workflow/v1.json',
        'version' => 1,
        'graph' => [
            'nodes' => [
                ['id' => 'b', 'kind' => 'brancher'],
                ['id' => 'sink', 'kind' => 'sink'],
            ],
            'edges' => [
                ['id' => 'e1', 'source' => 'b', 'target' => 'sink', 'sourceHandle' => 'yes'],
            ],
        ],
    ])->graph;

    $seen = null;

    $executors = (new ExecutorRegistry())
        ->bind('brancher', fn (): mixed => Port::branch('yes', $payload))
        ->bind('sink', function ($ctx) use (&$seen): mixed {
            // The RAW port, read with array_key_exists. Both `input('in', …)`
            // and `?? '__absent__'` fall back on a NULL value, so either would
            // hide the exact thing under test. This assertion has to make the
            // same distinction the fix does, or it cannot see the bug.
            $seen = array_key_exists('in', $ctx->inputs) ? $ctx->inputs['in'] : '__absent__';

            return null;
        });

    (new FlowRunner())->run($graph, $executors);

    return $seen;
}

it('passes a null payload through as null, not as the wrapper', function () {
    // `null`, not the wrapper and not absent: the port is bound, to null.
    expect(runBranchWith(null))->toBeNull();
});

it('still passes a real payload through unchanged', function () {
    // The behaviour the `?? $result` fallback was protecting: a normal payload
    // reaches the next node untouched.
    expect(runBranchWith(['user' => 'ada']))->toBe(['user' => 'ada']);
});

it('a bare branch with NO value key still yields the whole result', function () {
    // The case `?? $result` genuinely existed for, and the reason the fix is
    // `array_key_exists` rather than deleting the fallback: a host returning
    // `['branch' => 'yes']` with no `value` at all means "route there, and the
    // result IS the payload".
    $graph = Workflow::import([
        '$schema' => 'https://particle.academy/schemas/workflow/v1.json',
        'version' => 1,
        'graph' => [
            'nodes' => [['id' => 'b', 'kind' => 'brancher'], ['id' => 'sink', 'kind' => 'sink']],
            'edges' => [['id' => 'e1', 'source' => 'b', 'target' => 'sink', 'sourceHandle' => 'yes']],
        ],
    ])->graph;

    $seen = null;
    $executors = (new ExecutorRegistry())
        ->bind('brancher', fn (): mixed => ['branch' => 'yes', 'extra' => 1])
        ->bind('sink', function ($ctx) use (&$seen): mixed {
            $seen = array_key_exists('in', $ctx->inputs) ? $ctx->inputs['in'] : '__absent__';

            return null;
        });

    (new FlowRunner())->run($graph, $executors);

    expect($seen)->toBe(['branch' => 'yes', 'extra' => 1]);
});
