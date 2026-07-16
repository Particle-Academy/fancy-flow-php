<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Human;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Support\Notifier;
use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * `notify` — send a message via a {@see Notifier} (Slack / email / SMS / …).
 * The message is resolved through {@see Expr} against the node's inputs.
 */
final class NotifyExecutor implements NodeExecutor
{
    public function __construct(private readonly Notifier $notifier) {}

    public function execute(ExecutionContext $ctx): mixed
    {
        $channel = (string) $ctx->option('channel', 'slack');
        $to = (string) $ctx->option('to', '');
        $message = Expr::text(Expr::evaluate($ctx->option('message', ''), $ctx->inputs));

        $this->notifier->notify($channel, $to, $message);
        $ctx->emit(RunEvent::log('info', "notify → {$channel}:{$to}", $ctx->node->id));

        return ['sent' => true, 'channel' => $channel, 'to' => $to, 'message' => $message];
    }
}
