<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Console;

use FancyFlow\NodeKindRegistry;
use Illuminate\Console\Command;

final class ListKindsCommand extends Command
{
    protected $signature = 'flow:list-kinds {--category= : Filter by category}';

    protected $description = 'List the registered fancy-flow node kinds';

    public function handle(NodeKindRegistry $kinds): int
    {
        $category = $this->option('category');
        $category = is_string($category) && $category !== '' ? $category : null;

        $rows = [];
        foreach ($kinds->all($category) as $kind) {
            $rows[] = [$kind->name, $kind->category, $kind->label, (string) count($kind->configSchema)];
        }
        usort($rows, static fn (array $a, array $b) => [$a[1], $a[0]] <=> [$b[1], $b[0]]);

        $this->table(['kind', 'category', 'label', '#config'], $rows);
        $this->info(count($rows).' kinds registered.');

        return self::SUCCESS;
    }
}
