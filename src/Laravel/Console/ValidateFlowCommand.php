<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Console;

use FancyFlow\Laravel\FancyFlowManager;
use Illuminate\Console\Command;

final class ValidateFlowCommand extends Command
{
    protected $signature = 'flow:validate {file : Path to a WorkflowSchema JSON file} {--lenient}';

    protected $description = 'Validate a WorkflowSchema against the registered kinds';

    public function handle(FancyFlowManager $flow): int
    {
        $path = (string) $this->argument('file');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $result = $flow->import((string) file_get_contents($path), (bool) $this->option('lenient'));

        foreach ($result->issues as $issue) {
            $where = $issue->nodeId ? " [node {$issue->nodeId}]" : ($issue->edgeId ? " [edge {$issue->edgeId}]" : '');
            $line = "{$issue->level}: {$issue->message}{$where}";
            $issue->isError() ? $this->error($line) : $this->warn($line);
        }

        if ($result->ok) {
            $this->info(sprintf(
                'Valid — %d node(s), %d edge(s), %d warning(s).',
                count($result->graph->nodes),
                count($result->graph->edges),
                count($result->warnings()),
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf('Invalid — %d error(s).', count($result->errors())));

        return self::FAILURE;
    }
}
