<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Durable;

use FancyFlow\Tests\Laravel\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Base case for the durable-run suite: persistence on, an in-memory SQLite DB
 * (migrated fresh per test), and the sync queue so RunWorkflowJob executes
 * inline — the whole durable path exercised without a worker.
 */
abstract class DurableTestCase extends TestCase
{
    use RefreshDatabase;

    protected function defineEnvironment($app): void
    {
        $app['config']->set('fancy-flow.persistence.enabled', true);
        $app['config']->set('fancy-flow.queue.tries', 1);
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'sync');
    }
}
