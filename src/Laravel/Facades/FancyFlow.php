<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Facades;

use FancyFlow\NodeKindRegistry;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\ImportResult;
use Illuminate\Support\Facades\Facade;

/**
 * @method static NodeKindRegistry kinds()
 * @method static \FancyFlow\ExecutorRegistry executors()
 * @method static ImportResult import(string|array $schema, bool $lenient = false)
 * @method static \FancyFlow\Laravel\FancyFlowManager registerKind(\FancyFlow\Registry\NodeKind|array $kind)
 * @method static \FancyFlow\Laravel\FancyFlowManager extend(string $kind, callable|\FancyFlow\Contracts\NodeExecutor|string $executor, \FancyFlow\Registry\NodeKind|array|null $definition = null)
 * @method static RunResult run(mixed $flow, array $initialInputs = [], ?callable $onEvent = null, ?\FancyFlow\Runtime\RunOptions $options = null, ?string $runId = null)
 *
 * @see \FancyFlow\Laravel\FancyFlowManager
 */
final class FancyFlow extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'fancy-flow';
    }
}
