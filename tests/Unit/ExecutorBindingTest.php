<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;

function ffCtx(string $kind): ExecutionContext
{
    return new ExecutionContext(new FlowNode(id: 'n', type: $kind), [], static function () {});
}

/**
 * fancy-flow-php#4 — an override must replace a builtin under EVERY id it
 * answers to.
 *
 * The builtins are bound under all three spellings and `resolveFor` tries the
 * node's literal id FIRST, so an override bound only under the bare name was
 * unreachable from a graph saved with the canonical one. It failed silently:
 * the plain executor ran, the run completed, and a human gate was walked past.
 */
it('binding one id of a builtin binds every id it answers to', function () {
    $mine = new class implements NodeExecutor
    {
        public function execute(ExecutionContext $ctx): mixed
        {
            return 'mine';
        }
    };

    $registry = Builtin::executors()->bind('user_input', $mine);

    foreach (['user_input', '@particle-academy/user_input', '@fancy/user_input'] as $id) {
        $resolved = $registry->resolveFor(new FlowNode(id: 'n', type: $id));
        expect($resolved)->not->toBeNull("nothing bound for {$id}");
        expect($resolved(ffCtx($id)))->toBe('mine', "override missed for {$id}");
    }
});

it('follows a RENAME, which convention alone cannot', function () {
    // `llm_router` was renamed from `llm_branch`. No prefix arithmetic gets you
    // between them — only the kind's declared alias list does, which is why the
    // expansion reads the kind index rather than deriving spellings.
    $mine = new class implements NodeExecutor
    {
        public function execute(ExecutionContext $ctx): mixed
        {
            return 'routed';
        }
    };

    $registry = Builtin::executors()->bind('llm_router', $mine);

    foreach (['llm_router', 'llm_branch', '@particle-academy/llm_router'] as $id) {
        $resolved = $registry->resolveFor(new FlowNode(id: 'n', type: $id));
        expect($resolved(ffCtx($id)))->toBe('routed', "override missed for {$id}");
    }
});

it('does not WRITE namespaced keys for a kind it has never heard of', function () {
    // The opposite mistake would be minting `@particle-academy/acme_thing`
    // bindings on somebody else's behalf. The bind side stays literal for an
    // unknown kind.
    //
    // Resolution still bridges bare <-> namespaced by CONVENTION, which is
    // long-standing and intended -- that is why the id is reachable below even
    // though no key was written for it. The distinction matters: we resolve
    // generously, we do not claim ownership.
    $mine = new class implements NodeExecutor
    {
        public function execute(ExecutionContext $ctx): mixed
        {
            return 'x';
        }
    };

    $registry = Builtin::executors()->bind('acme_thing', $mine);

    $keys = (function () {
        $ref = new ReflectionClass($this);
        $prop = $ref->getProperty('byKind');
        $prop->setAccessible(true);

        return array_keys($prop->getValue($this));
    })->call($registry);

    expect($keys)->toContain('acme_thing');
    expect($keys)->not->toContain('@particle-academy/acme_thing');
    expect($keys)->not->toContain('@fancy/acme_thing');
});

it('leaves the * fallback alone', function () {
    // `*` is a sentinel, not a kind. Expanding it into namespaced spellings
    // would invent bindings nobody asked for.
    $registry = Builtin::executors()->bind('*', fn () => 'fallback');

    expect($registry->hasFallback())->toBeTrue();
    expect($registry->resolveFor(new FlowNode(id: 'n', type: 'totally_unknown'))(
        ffCtx('totally_unknown')
    ))->toBe('fallback');
});
