<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * Owns an {@see AbortSignal} and can trip it. Mirrors the DOM `AbortController`
 * so host code reads familiarly:
 *
 *     $controller = new AbortController();
 *     (new FlowRunner())->run($graph, $executors, options: new RunOptions(signal: $controller->signal));
 *     // ...later, from another callback: $controller->abort('user cancelled');
 */
final class AbortController
{
    public readonly AbortSignal $signal;

    public function __construct()
    {
        $this->signal = new AbortSignal();
    }

    public function abort(?string $reason = null): void
    {
        $this->signal->markAborted($reason);
    }
}
