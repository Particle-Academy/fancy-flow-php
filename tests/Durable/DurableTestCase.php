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

        // Pin the SINGLE-job driver explicitly. This suite is the single
        // driver's suite — `PerNodeTestCase` is the other one — and it used to
        // get here by inheriting the shipped default. The moment that default
        // flipped to `per_node` in 0.11, this whole file quietly started
        // testing the other driver: three tests failed, and the ones that
        // passed were no longer testing what their names say. A harness must
        // not change meaning because a default moved underneath it.
        $app['config']->set('fancy-flow.queue.driver', 'single');
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);
        $app['config']->set('queue.default', 'sync');
    }
}
