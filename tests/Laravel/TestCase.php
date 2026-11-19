<?php

declare(strict_types=1);

namespace FancyFlow\Tests\Laravel;

use FancyFlow\Laravel\FancyFlowServiceProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Bootstrap\RegisterProviders;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;

/**
 * Base test case for the Laravel-integration suite: a hand-built Laravel
 * application (laravel/framework only, no orchestra/testbench) with the
 * fancy-flow service provider registered.
 *
 * The app lives in a throwaway base path, so there is no `.env`, no package
 * discovery and no app config: the framework's own default config, then the
 * overrides below. In-memory SQLite and blank provider keys are forced, so
 * nothing can reach a real database or a paid API.
 */
abstract class TestCase extends LaravelTestCase
{
    /**
     * `artisan()` runs the command and returns its exit code. Laravel's
     * PendingCommand output expectations are built on Mockery, which is not a
     * dependency here; read `Artisan::output()` instead.
     */
    public $mockConsoleOutput = false;
    public function createApplication(): Application
    {
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fancy-flow-php-tests';
        foreach (['bootstrap/cache', 'config', 'database', 'storage/framework/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $dir) {
            if (! is_dir($base.'/'.$dir)) {
                mkdir($base.'/'.$dir, 0777, true);
            }
        }
        // An empty .env, so the environment loader finds a file and reads nothing.
        if (! is_file($base.'/.env')) {
            touch($base.'/.env');
        }

        $app = Application::configure(basePath: $base)->withExceptions()->create();

        $app->afterBootstrapping(LoadConfiguration::class, function (Application $app): void {
            $config = $app['config'];
            $config->set('app.env', 'testing');
            // The environment was detected from config a moment ago; without this
            // it reads `production` and `migrate` stops to ask for confirmation.
            $app->detectEnvironment(fn (): string => 'testing');
            $config->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
            $config->set('app.providers', array_merge($config->get('app.providers', []), $this->getPackageProviders($app)));
            $config->set('app.aliases', array_merge($config->get('app.aliases', []), $this->getPackageAliases($app)));
            $config->set('database.default', 'sqlite');
            $config->set('database.connections.sqlite.database', ':memory:');
            $config->set('cache.default', 'array');
            $config->set('session.driver', 'array');
            $config->set('queue.default', 'sync');
            $config->set('mail.default', 'array');
            $config->set('logging.default', 'null');
            $config->set('services.anthropic.key', '');
            $config->set('services.openai.key', '');
        });

        // After every provider has registered and before any boots, so a
        // suite's bindings win over the provider's defaults.
        $app->afterBootstrapping(RegisterProviders::class, function (Application $app): void {
            $this->defineEnvironment($app);
        });

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    /**
     * Config and bindings a suite needs, applied once every service provider
     * has registered and before any of them boots.
     */
    protected function defineEnvironment($app): void
    {
    }

    /** Register an extra migrations directory, run by the next `migrate`. */
    protected function loadMigrationsFrom(string $path): void
    {
        $this->app->make('migrator')->path($path);
    }

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
