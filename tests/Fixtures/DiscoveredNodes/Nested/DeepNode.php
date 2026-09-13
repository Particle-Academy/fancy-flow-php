<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes\Nested;

use FancyFlow\Attributes\FlowNode;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Runtime\ExecutionContext;

#[FlowNode(name: 'discovered_deep')]
class DeepNode implements NodeExecutor
{
    public function execute(ExecutionContext $ctx): mixed
    {
        return 'deep';
    }
}
