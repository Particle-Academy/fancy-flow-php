<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/** A deterministic {@see Notifier} that records messages instead of sending them. */
final class RecordingNotifier implements Notifier
{
    /** @var list<array{channel:string,to:string,message:string}> */
    public array $sent = [];

    public function notify(string $channel, string $to, string $message): void
    {
        $this->sent[] = ['channel' => $channel, 'to' => $to, 'message' => $message];
    }
}
