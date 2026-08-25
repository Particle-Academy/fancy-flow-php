<?php

declare(strict_types=1);

use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;
use ParticleAcademy\Conformance\Conformance;

/**
 * The executor-resolution table, run against THIS side.
 *
 * `@particle-academy/fancy-flow` and `fancy-flow` (Python) run the identical
 * rows from the identical file: node id → kind → `*`, with alias resolution in
 * both directions and a closed failure when nothing matches.
 *
 * ## Why the table exists, and why PHP is not where the bug was
 *
 * The TypeScript runtime could run the **wrong executor, silently**: its alias
 * step resolved `data.kind`'s ids before `node.type`'s, so a node declaring
 * itself an `llm_call` ran an `output` executor — with the correct one
 * registered and unused. Nothing reported it, because running the wrong
 * executor and running the right one look identical from outside.
 *
 * **This runtime could not have that bug, and the reason is structural rather
 * than careful.** `FlowNode` here is FLATTENED: `$type` IS the kind and there
 * is no `data` slot for a second opinion to live in, so the precedence question
 * cannot arise. The six `0200` rows are therefore skipped here, each stating
 * that reason on the row itself.
 *
 * That asymmetry is worth reading rather than glossing: inventing a `data.kind`
 * field on this side so it could answer rows about one would be writing code to
 * satisfy a table, which is the inversion the conformance package exists to
 * prevent. The eight `0100` rows are the shared claim, and they are real ones —
 * this side expands aliases at BIND time while TypeScript expands them at
 * LOOKUP time, so the table asserts which executor RUNS rather than which key
 * matched, and both strategies have to agree on it.
 */

/** A throwaway context: the executors here only report which one they are. */
function conformanceContext(FlowNode $node): ExecutionContext
{
    return new ExecutionContext(
        node: $node,
        inputs: [],
        emit: static function (): void {},
    );
}

/**
 * Run one case: build the case's own kind registry and bindings, then resolve.
 *
 * Each binding runs a recogniser returning its label, so the answer is WHICH
 * executor was chosen — the only thing a consumer can actually observe, and the
 * only formulation that is neutral about when a runtime expands aliases.
 *
 * @param  array<string,mixed>  $case
 */
function runExecutorResolutionCase(array $case): ?string
{
    $kinds = new NodeKindRegistry;

    foreach ($case['input']['kinds'] as $declared) {
        $kinds->register(new NodeKind(
            name: $declared['name'],
            category: 'conformance',
            label: $declared['name'],
            aliases: $declared['aliases'] ?? [],
        ));
    }

    $executors = new ExecutorRegistry(kinds: $kinds);
    $node = $case['input']['node'];

    foreach ($case['input']['bindings'] as $binding) {
        $label = $binding['executor'];
        $run = static fn (): string => $label;

        // A binding keyed by the node's own id is a NODE binding, not a kind
        // binding — the two live in different maps here, so the case's flat
        // list has to be routed. Keying by id is how a graph pins one node to a
        // stub without unbinding that kind everywhere else.
        if ($binding['key'] === $node['id']) {
            $executors->bindNode($binding['key'], $run);
        } else {
            $executors->bind($binding['key'], $run);
        }
    }

    $flowNode = new FlowNode(id: $node['id'], type: $node['type'] ?? null);
    $resolved = $executors->resolveFor($flowNode);

    return $resolved === null ? null : $resolved(conformanceContext($flowNode));
}

it('matches the flow/executor-resolution table on every case', function (): void {
    $summary = Conformance::runTable('flow/executor-resolution', runExecutorResolutionCase(...));

    // Printed unconditionally so a green build still shows WHAT was compared,
    // including which rows were skipped and why. A bare "6 skipped" reads
    // identically to full coverage; the reasons are the whole point.
    echo "\n".Conformance::formatSummary($summary)."\n";

    $failures = array_filter(
        $summary['results'] ?? [],
        static fn (array $r): bool => ($r['status'] ?? '') === 'fail',
    );

    expect($failures)->toBe([], 'PHP disagrees with the shared table on: '.implode(
        ', ',
        array_column($failures, 'id'),
    ));

    expect($summary['failed'])->toBe(0);

    // The vacuity floor, set just under the eight rows that actually run here.
    // A suite that skipped everything reports zero failures too, so without
    // this a stale package or a bad path would pass quietly — which is the
    // failure mode the conformance package exists to argue against.
    expect($summary['passed'])->toBeGreaterThan(6);
});

it('resolves an alias binding in the direction a consumer actually hit', function (): void {
    // The discrimination check. The table above could pass against an
    // implementation that resolves aliases in only ONE direction if the rows
    // happened not to exercise the other — so the asymmetric case is pinned
    // directly, here, in the spelling that was reported broken.
    $kinds = new NodeKindRegistry;
    $kinds->register(new NodeKind(
        name: '@particle-academy/manual_trigger',
        category: 'triggers',
        label: 'Manual trigger',
        aliases: ['manual_trigger'],
    ));

    // Bound under the BARE name, resolved by the NAMESPACED id — which is what
    // `resolveKindId()` hands a caller, and therefore the natural key for a
    // registry to use.
    $executors = new ExecutorRegistry(kinds: $kinds);
    $executors->bind('manual_trigger', static fn (): string => 'hit');

    $node = new FlowNode(id: 'n1', type: '@particle-academy/manual_trigger');
    $resolved = $executors->resolveFor($node);

    expect($resolved)->not->toBeNull('a namespaced node must reach a bare binding');
    expect($resolved(conformanceContext($node)))->toBe('hit');
});
