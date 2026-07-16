<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Console;

use FancyFlow\Laravel\FancyFlowManager;
use FancyFlow\Runtime\RunEvent;
use Illuminate\Console\Command;

final class RunFlowCommand extends Command
{
    protected $signature = 'flow:run
        {workflow : Path to a WorkflowSchema JSON file}
        {--input= : JSON object of initial inputs, keyed by node id}
        {--pretty : Pretty-print the outputs}';

    protected $description = 'Run a fancy-flow workflow from a WorkflowSchema JSON file';

    public function handle(FancyFlowManager $flow): int
    {
        $path = (string) $this->argument('workflow');
        if (! is_file($path)) {
            $this->error("File not found: {$path}");

            return self::FAILURE;
        }

        $schema = json_decode((string) file_get_contents($path), true);
        if (! is_array($schema)) {
            $this->error('Invalid JSON in workflow file.');

            return self::FAILURE;
        }

        $inputs = [];
        $rawInput = $this->option('input');
        if (is_string($rawInput) && $rawInput !== '') {
            $inputs = json_decode($rawInput, true);
            if (! is_array($inputs)) {
                $this->error('--input must be a JSON object.');

                return self::FAILURE;
            }
        }

        $result = $flow->run($schema, $inputs, function (RunEvent $event): void {
            if ($event->type === RunEvent::NODE_STATUS) {
                $text = $event->text !== null ? " ({$event->text})" : '';
                $this->line("  <fg=gray>{$event->nodeId}</> {$event->status}{$text}");
            }
        });

        if (! $result->ok) {
            $this->error("Run failed: {$result->error}");

            return self::FAILURE;
        }

        $flags = JSON_UNESCAPED_SLASHES | ($this->option('pretty') ? JSON_PRETTY_PRINT : 0);
        $this->info('Run OK.');
        $this->line((string) json_encode($result->outputs, $flags));

        return self::SUCCESS;
    }
}
