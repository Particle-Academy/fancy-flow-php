<?php

declare(strict_types=1);

use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowNode;

it('registers, gets, and lists kinds', function () {
    $r = new NodeKindRegistry();
    $r->register(NodeKind::fromArray(['name' => 'foo', 'category' => 'logic', 'label' => 'Foo']));

    expect($r->has('foo'))->toBeTrue();
    expect($r->get('foo'))->toBeInstanceOf(NodeKind::class);
    expect($r->get('missing'))->toBeNull();
    expect($r->all())->toHaveCount(1);
});

it('filters kinds by category', function () {
    $r = new NodeKindRegistry();
    $r->register(NodeKind::fromArray(['name' => 'a', 'category' => 'logic', 'label' => 'A']));
    $r->register(NodeKind::fromArray(['name' => 'b', 'category' => 'ai', 'label' => 'B']));

    expect($r->all('logic'))->toHaveCount(1);
    expect($r->all('ai')[0]->name)->toBe('b');
});

it('builds default config from defaultConfig and field defaults', function () {
    $r = new NodeKindRegistry();
    $kind = NodeKind::fromArray([
        'name' => 'k', 'category' => 'logic', 'label' => 'K',
        'defaultConfig' => ['a' => 1],
        'configSchema' => [
            ['type' => 'text', 'key' => 'b', 'label' => 'B', 'default' => 'z'],
            ['type' => 'number', 'key' => 'c', 'label' => 'C'], // no default → not added
        ],
    ]);

    expect($r->defaultConfigFor($kind))->toBe(['a' => 1, 'b' => 'z']);
});

it('does not overwrite a present config key even when null', function () {
    $r = new NodeKindRegistry();
    $kind = NodeKind::fromArray([
        'name' => 'k', 'category' => 'logic', 'label' => 'K',
        'defaultConfig' => ['b' => null],
        'configSchema' => [['type' => 'text', 'key' => 'b', 'label' => 'B', 'default' => 'z']],
    ]);

    expect($r->defaultConfigFor($kind))->toBe(['b' => null]);
});

it('validates required fields', function () {
    $r = new NodeKindRegistry();
    $kind = NodeKind::fromArray([
        'name' => 'k', 'category' => 'logic', 'label' => 'K',
        'configSchema' => [['type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true]],
    ]);

    expect($r->validateConfig($kind, []))->toHaveCount(1);
    expect($r->validateConfig($kind, ['name' => '']))->toHaveCount(1);
    expect($r->validateConfig($kind, ['name' => 'ok']))->toBe([]);
});

it('validates number bounds and select membership', function () {
    $r = new NodeKindRegistry();
    $kind = NodeKind::fromArray([
        'name' => 'k', 'category' => 'logic', 'label' => 'K',
        'configSchema' => [
            ['type' => 'number', 'key' => 'n', 'label' => 'N', 'min' => 1, 'max' => 10],
            ['type' => 'select', 'key' => 's', 'label' => 'S', 'options' => [['value' => 'a', 'label' => 'A'], ['value' => 'b', 'label' => 'B']]],
            ['type' => 'switch', 'key' => 'on', 'label' => 'On'],
        ],
    ]);

    expect($r->validateConfig($kind, ['n' => 5, 's' => 'a', 'on' => true]))->toBe([]);
    expect($r->validateConfig($kind, ['n' => 0]))->not->toBe([]);
    expect($r->validateConfig($kind, ['n' => 99]))->not->toBe([]);
    expect($r->validateConfig($kind, ['n' => 'x']))->not->toBe([]);
    expect($r->validateConfig($kind, ['s' => 'z']))->not->toBe([]);
    expect($r->validateConfig($kind, ['on' => 'yes']))->not->toBe([]);
});

it('exposes a resettable shared default registry', function () {
    NodeKindRegistry::resetDefault();
    $a = NodeKindRegistry::default();
    $a->register(NodeKind::fromArray(['name' => 'temp', 'category' => 'logic', 'label' => 'T']));
    expect(NodeKindRegistry::default()->has('temp'))->toBeTrue();

    NodeKindRegistry::resetDefault();
    expect(NodeKindRegistry::default()->has('temp'))->toBeFalse();
});

it('maps category accents', function () {
    expect(NodeKindRegistry::categoryAccent('trigger'))->toBe('#10b981');
    expect(NodeKindRegistry::categoryAccent('unknown-cat'))->toBe('#71717a');
});

it('resolves a NodeExecutor class-string via the native resolver', function () {
    $registry = (new ExecutorRegistry())->bind('k', FixtureAddOne::class);
    $node = new FlowNode(id: 'n', type: 'k');
    $exec = $registry->resolveFor($node);

    expect($exec)->not->toBeNull();
    expect($exec(new ExecutionContext($node, ['in' => 41], fn () => null)))->toBe(42);
});

final class FixtureAddOne implements FancyFlow\Contracts\NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return ((int) $ctx->input('in')) + 1;
    }
}
