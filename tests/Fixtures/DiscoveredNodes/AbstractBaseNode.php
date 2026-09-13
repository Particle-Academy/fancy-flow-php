<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes;

use FancyFlow\Attributes\FlowNode;
use FancyFlow\Contracts\NodeExecutor;

/** Carries the attribute but cannot be constructed, so it is not a node. */
#[FlowNode(name: 'discovered_abstract')]
abstract class AbstractBaseNode implements NodeExecutor
{
}
