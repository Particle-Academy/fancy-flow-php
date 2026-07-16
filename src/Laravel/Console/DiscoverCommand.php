<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Console;

use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Laravel\FlowNodeDiscovery;
use FancyFlow\Registry\NodeKind;
use Illuminate\Console\Command;

final class DiscoverCommand extends Command
{
    protected $signature = 'flow:discover';

    protected $description = 'Discover #[FlowNode] executor classes and register their kinds + executors';

    public function handle(FancyFlowManager $flow): int
    {
        $paths = (array) config('fancy-flow.discover', []);
        if ($paths === []) {
            $this->warn('No discovery paths configured (config/fancy-flow.php → discover).');

            return self::SUCCESS;
        }

        $found = FlowNodeDiscovery::scan($paths);
        foreach ($found as $entry) {
            $attr = $entry['attribute'];
            $flow->extend($attr->name, $entry['class'], NodeKind::fromArray($attr->toKindArray()));
            $this->line("  registered <info>{$attr->name}</info> → {$entry['class']}");
        }

        $this->info(count($found).' #[FlowNode] executor(s) discovered.');

        return self::SUCCESS;
    }
}
