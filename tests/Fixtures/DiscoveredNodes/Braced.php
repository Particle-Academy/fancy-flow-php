<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes\Braced {
    use FancyFlow\Attributes\FlowNode;
    use FancyFlow\Contracts\NodeExecutor;
    use FancyFlow\Runtime\ExecutionContext;

    #[FlowNode(name: 'discovered_braced_one')]
    final class One implements NodeExecutor
    {
        public function execute(ExecutionContext $ctx): mixed
        {
            return 'one';
        }
    }
}

namespace FancyFlow\Tests\Fixtures\DiscoveredNodes\BracedToo {
    use FancyFlow\Attributes\FlowNode;
    use FancyFlow\Contracts\NodeExecutor;
    use FancyFlow\Runtime\ExecutionContext;

    #[FlowNode(name: 'discovered_braced_two')]
    final readonly class Two implements NodeExecutor
    {
        public function execute(ExecutionContext $ctx): mixed
        {
            return 'two';
        }
    }
}
