<?php

declare(strict_types=1);

use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\KindId;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;

it('publishes built-in kinds under canonical namespaced ids', function () {
    $registry = Builtin::register(new NodeKindRegistry());
    $branch = $registry->get('branch');

    expect($branch->name)->toBe('@particle-academy/branch')
        ->and($branch->aliases)->toContain('branch', '@fancy/branch')
        ->and($branch->ids())->toBe(['@particle-academy/branch', 'branch', '@fancy/branch']);
});

it('resolves a kind by canonical id, bare alias, or legacy namespace', function () {
    $registry = Builtin::register(new NodeKindRegistry());

    foreach (['@particle-academy/branch', 'branch', '@fancy/branch'] as $id) {
        expect($registry->has($id))->toBeTrue("should resolve {$id}");
        expect($registry->get($id)?->name)->toBe('@particle-academy/branch');
        expect($registry->resolveKindId($id))->toBe('@particle-academy/branch');
    }

    expect($registry->resolveKindId('@acme/branch'))->toBeNull();
});

it('unregisters a kind by any of its ids, clearing its aliases too', function () {
    $registry = Builtin::register(new NodeKindRegistry());

    $registry->unregister('branch');

    expect($registry->has('branch'))->toBeFalse()
        ->and($registry->has('@particle-academy/branch'))->toBeFalse()
        ->and($registry->has('@fancy/branch'))->toBeFalse();
});

it('keeps a host executor bound under the BARE name working for a canonical graph', function () {
    // The trap: canonical ids are namespaced, but a host bound its executor
    // under the bare name. Resolving only the literal string would make a
    // rename a breaking change in disguise.
    $executors = (new ExecutorRegistry())->bind('geocode', fn (ExecutionContext $ctx) => 'bare-hit');

    $node = ffNode('n', '@particle-academy/geocode');

    expect($executors->resolveFor($node))->not->toBeNull();
    expect(($executors->resolveFor($node))(new ExecutionContext($node, [], fn () => null)))->toBe('bare-hit');
    expect($executors->hasKind('@particle-academy/geocode'))->toBeTrue();
});

it('keeps a graph saved with a BARE kind running against canonical bindings', function () {
    // The other direction: the built-ins bind canonically, and every graph
    // written before namespacing still says `branch`.
    $executors = Builtin::executors();

    expect($executors->resolveFor(ffNode('n', 'branch')))->not->toBeNull()
        ->and($executors->resolveFor(ffNode('n', '@fancy/branch')))->not->toBeNull()
        ->and($executors->resolveFor(ffNode('n', '@particle-academy/branch')))->not->toBeNull();
});

it('resolves executors through a custom kind alias that follows no convention', function () {
    $kinds = (new NodeKindRegistry())->register(NodeKind::fromArray([
        'name' => '@acme/salesforce_upsert',
        'category' => 'io',
        'label' => 'Upsert',
        'aliases' => ['sf_upsert_v1'],
    ]));
    $executors = (new ExecutorRegistry(null, $kinds))->bind('sf_upsert_v1', fn () => 'legacy');

    expect($executors->resolveFor(ffNode('n', '@acme/salesforce_upsert')))->not->toBeNull();
});

it('keeps every previously-shipped llm_branch id resolving after the llm_router rename', function () {
    // The rename that the alias mechanism exists for: documents and flows
    // already carry `llm_branch`, and a persisted kind id cannot be migrated
    // after the fact.
    $registry = Builtin::register(new NodeKindRegistry());

    foreach (['llm_router', '@particle-academy/llm_router', 'llm_branch', '@fancy/llm_branch'] as $id) {
        expect($registry->get($id)?->name)->toBe('@particle-academy/llm_router', "should resolve {$id}");
    }

    expect($registry->get('llm_branch')->label)->toBe('LLM Router');

    // …and the executor still resolves for a graph saved under the old id.
    expect(Builtin::executors()->resolveFor(ffNode('n', 'llm_branch')))->not->toBeNull();
});

it('does not mistake a third party namespace for a built-in', function () {
    expect(KindId::matches('@acme/note', 'note'))->toBeFalse()
        ->and(KindId::matches('note', 'note'))->toBeTrue()
        ->and(KindId::matches('@particle-academy/note', 'note'))->toBeTrue()
        ->and(KindId::matches('@fancy/note', 'note'))->toBeTrue();
});

it('round-trips aliases through the manifest serialization', function () {
    $kind = NodeKind::fromArray(['name' => '@acme/thing', 'category' => 'io', 'label' => 'Thing', 'aliases' => ['thing']]);

    expect($kind->toArray()['aliases'])->toBe(['thing']);
    expect(NodeKind::fromArray($kind->toArray())->aliases)->toBe(['thing']);
});
