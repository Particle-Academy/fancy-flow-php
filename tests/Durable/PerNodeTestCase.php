<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Durable;

/**
 * The durable suite, on the `per_node` queue driver.
 *
 * Everything else is inherited: persistence on, in-memory SQLite migrated per
 * test, and the sync queue — which for this driver means the whole
 * advance → node → advance chain runs inline, so a test can dispatch a run and
 * assert the finished state. Tests that need to control the chain step by step
 * (a worker dying mid-run, two workers racing) fake the queue instead and pump
 * it themselves.
 */
abstract class PerNodeTestCase extends DurableTestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('fancy-flow.queue.driver', 'per_node');
    }
}
