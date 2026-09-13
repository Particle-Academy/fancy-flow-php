<?php

declare(strict_types=1);

use FancyFlow\Laravel\FlowNodeDiscovery;
use FancyFlow\Tests\Fixtures\DiscoveredNodes\Braced\One;
use FancyFlow\Tests\Fixtures\DiscoveredNodes\BracedToo\Two;
use FancyFlow\Tests\Fixtures\DiscoveredNodes\EchoNode;
use FancyFlow\Tests\Fixtures\DiscoveredNodes\Nested\DeepNode;

/**
 * What `#[FlowNode]` discovery finds.
 *
 * Until 0.51.1 nothing in this suite called `scan()` at all, even though it is
 * the path every host with `fancy-flow.discover` configured boots through. So
 * when the scan was rewritten to read the files it walks instead of every class
 * in the process (issue #15), these pin what it must still return.
 */
function discoveredClasses(array $paths): array
{
    return sortedClasses(array_map(static fn (array $entry): string => $entry['class'], FlowNodeDiscovery::scan($paths)));
}

/** Compared as sets: the order classes come back in is not part of the contract. */
function sortedClasses(array $classes): array
{
    sort($classes);

    return $classes;
}

const DISCOVERY_FIXTURES = __DIR__.'/../Fixtures/DiscoveredNodes';

it('finds every instantiable #[FlowNode] class under the path, and nothing else', function () {
    // Excluded on purpose: an abstract class carrying the attribute, a class
    // without it, and an interface, trait and enum. EchoNode also names a class
    // with `::class` and builds an anonymous class, neither of which declares one.
    expect(discoveredClasses([DISCOVERY_FIXTURES]))->toBe(sortedClasses([
        One::class,
        Two::class,
        EchoNode::class,
        DeepNode::class,
    ]));
});

it('carries the attribute instance for each class', function () {
    $byClass = [];
    foreach (FlowNodeDiscovery::scan([DISCOVERY_FIXTURES]) as $entry) {
        $byClass[$entry['class']] = $entry['attribute']->name;
    }

    expect($byClass[EchoNode::class])->toBe('discovered_echo')
        ->and($byClass[Two::class])->toBe('discovered_braced_two')
        ->and($byClass[DeepNode::class])->toBe('discovered_deep');
});

it('finds a node class that was autoloaded before the scan ran', function () {
    // A host often references a node class in its own provider before this
    // package boots. `require_once` is then a no-op for that file, so any scan
    // that only looks at what its includes newly declared would miss it.
    expect(class_exists(EchoNode::class))->toBeTrue();

    expect(discoveredClasses([DISCOVERY_FIXTURES]))->toContain(EchoNode::class);
});

it('reports a class once when two configured paths overlap', function () {
    expect(discoveredClasses([DISCOVERY_FIXTURES, DISCOVERY_FIXTURES.'/Nested']))
        ->toBe(sortedClasses([One::class, Two::class, EchoNode::class, DeepNode::class]));
});

it('returns nothing for paths that do not exist', function () {
    expect(FlowNodeDiscovery::scan([DISCOVERY_FIXTURES.'/missing']))->toBe([]);
});
