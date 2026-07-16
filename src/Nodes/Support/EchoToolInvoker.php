<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/** A deterministic {@see ToolInvoker} that echoes the tool + args. Records calls in {@see $calls}. */
final class EchoToolInvoker implements ToolInvoker
{
    /** @var list<array{tool:string,args:array<string,mixed>}> */
    public array $calls = [];

    public function invoke(string $tool, array $args = []): mixed
    {
        $this->calls[] = ['tool' => $tool, 'args' => $args];

        return ['tool' => $tool, 'args' => $args];
    }
}
