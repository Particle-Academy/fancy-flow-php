<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Laravel;

use FancyFlow\Laravel\FancyFlowServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base test case for the Laravel-integration suite. Boots a minimal Laravel app
 * (Orchestra Testbench) with the fancy-flow service provider registered.
 */
abstract class TestCase extends Orchestra
{
    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [FancyFlowServiceProvider::class];
    }

    /** @return array<string,class-string> */
    protected function getPackageAliases($app): array
    {
        return ['FancyFlow' => \FancyFlow\Laravel\Facades\FancyFlow::class];
    }
}
