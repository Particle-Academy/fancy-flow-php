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
    /**
     * The optional LLM libraries are dev-only here, so their providers are
     * registered when present rather than assumed — the adapter tests skip
     * themselves when the library isn't installed.
     *
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        $providers = [FancyFlowServiceProvider::class];

        foreach ([\Prism\Prism\PrismServiceProvider::class, \Laravel\Ai\AiServiceProvider::class] as $optional) {
            if (class_exists($optional)) {
                $providers[] = $optional;
            }
        }

        return $providers;
    }

    /** @return array<string,class-string> */
    protected function getPackageAliases($app): array
    {
        return ['FancyFlow' => \FancyFlow\Laravel\Facades\FancyFlow::class];
    }
}
