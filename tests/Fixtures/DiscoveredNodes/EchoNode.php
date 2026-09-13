<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes;

use FancyFlow\Attributes\FlowNode;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

/**
 * Written to trip a reader that finds classes by looking for the word `class`:
 * it names a class with `::class` and builds an anonymous class, and neither of
 * those declares anything.
 */
#[FlowNode(name: 'discovered_echo', label: 'Discovered echo')]
final class EchoNode implements NodeExecutor
{
    public const SELF = EchoNode::class;

    public function execute(ExecutionContext $ctx): mixed
    {
        $helper = new class {
            public function tag(): string
            {
                return 'echoed';
            }
        };

        return ['tag' => $helper->tag()];
    }
}
