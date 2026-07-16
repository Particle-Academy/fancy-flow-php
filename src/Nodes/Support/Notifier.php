<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * Delivers a message for the notify / human_approval executors. The default
 * {@see RecordingNotifier} records without sending; the Laravel layer binds
 * Notifications (Slack / mail / SMS / …).
 */
interface Notifier
{
    public function notify(string $channel, string $to, string $message): void;
}
