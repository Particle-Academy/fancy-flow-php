<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Clients;

use FancyFlow\Nodes\Support\Notifier;
use Psr\Log\LoggerInterface;

/**
 * The default Laravel {@see Notifier} for the notify / human_approval executors
 * — writes each message to the log. Apps bind their own (Slack / mail / SMS via
 * Laravel Notifications) by rebinding `Notifier::class` in the container.
 */
final class LogNotifier implements Notifier
{
    public function __construct(private readonly LoggerInterface $log) {}

    public function notify(string $channel, string $to, string $message): void
    {
        $this->log->info('[fancy-flow] notify', [
            'channel' => $channel,
            'to' => $to,
            'message' => $message,
        ]);
    }
}
