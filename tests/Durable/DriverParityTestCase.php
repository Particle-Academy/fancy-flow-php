<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Durable;

use FancyFlow\Nodes\Support\EchoHttpClient;
use FancyFlow\Nodes\Support\HttpClient;
use FancyFlow\Nodes\Support\Notifier;
use FancyFlow\Nodes\Support\RecordingNotifier;

/**
 * The per-node driver, wired to the same deterministic clients the parity
 * fixtures were baked against.
 *
 * The Laravel provider swaps in real backends — an HTTP client that would make
 * a network call, a notifier that logs. Those are the right defaults for an app
 * and the wrong ones for a golden-output comparison, so this rebinds the two
 * that differ. Everything else (the engine, the executors, the driver) is
 * exactly what a host runs.
 */
abstract class DriverParityTestCase extends PerNodeTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app->bind(HttpClient::class, EchoHttpClient::class);
        $app->bind(Notifier::class, RecordingNotifier::class);
    }
}
