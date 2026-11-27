<?php

declare(strict_types=1);

/**
 * `for_each` says what a `results` entry looks like.
 *
 * Each entry is that iteration's node outputs KEYED BY NODE ID, and a failed
 * item's entry is null. The description only said "each iteration's node
 * results", and lab run 1406 (test-9) showed what that costs: the builder wrote
 * `{{ in.results.0.total }}` where the value is at `results.0.score-1.total`,
 * so a correct run reported a broken assert. An authoring agent reads this text
 * through describe_node_kind and list_node_kinds, so the text is the contract.
 */

use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Logic\ForEachExecutor;
use FancyFlow\Registry\Builtin;

function forEachKind(): FancyFlow\Registry\NodeKind
{
    return Builtin::register(new NodeKindRegistry())->get('for_each');
}

function resultsField(): array
{
    foreach (forEachKind()->outputShapeFor([]) ?? [] as $field) {
        $field = is_array($field) ? $field : $field->toArray();
        if (($field['path'] ?? null) === 'results') {
            return $field;
        }
    }

    return [];
}

it('describes results entries as outputs keyed by node id', function () {
    $text = (string) (resultsField()['description'] ?? '');

    expect($text)->toContain('keyed by node id')
        ->and($text)->toContain('results.0.<node-id>.<field>')
        ->and($text)->toContain('null');
});

it('says the same in the kind description an agent lists', function () {
    $text = forEachKind()->description;

    expect($text)->toContain('keyed by node id')
        ->and($text)->toContain('results.0.<node-id>.<field>')
        ->and($text)->toContain('failures');
});

it('matches what the executor actually produces', function () {
    // The description is a claim about the executor; pin the executor to it.
    $source = (string) file_get_contents((new ReflectionClass(ForEachExecutor::class))->getFileName());

    expect($source)->toContain('$results[] = $nested->outputs;')
        ->and($source)->toContain('$results[] = null;');
});
