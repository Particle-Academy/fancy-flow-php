<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\LlmClient as RouteLlmClient;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\Clients\CacheStore;
use FancyFlow\Laravel\Clients\LaravelHttpClient;
use FancyFlow\Laravel\Clients\LogNotifier;
use FancyFlow\Laravel\Console\DiscoverCommand;
use FancyFlow\Laravel\Console\ListKindsCommand;
use FancyFlow\Laravel\Console\RunFlowCommand;
use FancyFlow\Laravel\Console\ValidateFlowCommand;
use FancyFlow\Laravel\Http\FlowRoutes;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Ai\AgentExecutor;
use FancyFlow\Nodes\Support\EchoLlmClient;
use FancyFlow\Nodes\Support\EchoToolInvoker;
use FancyFlow\Nodes\Support\EmptyVectorStore;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Nodes\Support\HttpClient;
use FancyFlow\Nodes\Support\LlmClient;
use FancyFlow\Nodes\Support\Notifier;
use FancyFlow\Nodes\Support\ToolInvoker;
use FancyFlow\Nodes\Support\VectorStore;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\NodeKind;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the framework-free core into Laravel:
 *   - the 22 built-in kinds (+ config kinds) in a shared {@see NodeKindRegistry};
 *   - container-resolved executors (constructor DI) over Laravel-backed clients
 *     (HTTP client, cache stores, log notifier) — each rebindable by the app;
 *   - the {@see FancyFlowManager} (facade `FancyFlow`, alias `fancy-flow`);
 *   - Artisan commands + #[FlowNode] discovery + a publishable config.
 */
final class FancyFlowServiceProvider extends ServiceProvider
{
    private const CONFIG = __DIR__.'/../../config/fancy-flow.php';

    public function register(): void
    {
        $this->mergeConfigFrom(self::CONFIG, 'fancy-flow');

        // Default client bindings — rebind any of these in your app to swap the
        // backend (e.g. a real LlmClient, a Slack Notifier).
        $this->app->bind(HttpClient::class, LaravelHttpClient::class);
        $this->app->bind(Notifier::class, LogNotifier::class);
        $this->app->bind(LlmClient::class, EchoLlmClient::class);
        $this->app->bind(ToolInvoker::class, EchoToolInvoker::class);
        $this->app->bind(VectorStore::class, EmptyVectorStore::class);

        $this->app->singleton(NodeKindRegistry::class, function (Container $app): NodeKindRegistry {
            $config = (array) $app['config']['fancy-flow'];
            $registry = Builtin::register(new NodeKindRegistry(), withStructural: (bool) ($config['structural_kinds'] ?? true));
            if ((bool) ($config['agentic'] ?? true)) {
                $registry->register(NodeKind::fromArray(Builtin::agentKind()));
            }
            foreach ((array) ($config['kinds'] ?? []) as $kind) {
                $registry->register($kind instanceof NodeKind ? $kind : NodeKind::fromArray($kind));
            }

            return $registry;
        });

        $this->app->singleton(ExecutorRegistry::class, function (Container $app): ExecutorRegistry {
            $config = (array) $app['config']['fancy-flow'];
            $deps = $this->buildDeps($app, $config);
            $registry = Builtin::executors($deps, new ContainerResolver($app));
            if ((bool) ($config['agentic'] ?? true)) {
                $registry->bind('agent', new AgentExecutor($deps->llm, $deps->tools));
            }
            foreach ((array) ($config['executors'] ?? []) as $kind => $executor) {
                $registry->bind((string) $kind, $executor);
            }

            return $registry;
        });

        // `subflow` needs somewhere to look workflows up. With persistence on
        // they already live in the workflows table, so the primitive works out
        // of the box; rebind WorkflowResolver to point it elsewhere.
        $this->app->bindIf(
            WorkflowResolver::class,
            fn (Container $app): WorkflowResolver => new EloquentWorkflowResolver($app->make(NodeKindRegistry::class)),
        );

        $this->app->singleton(FancyFlowManager::class, fn (Container $app): FancyFlowManager => new FancyFlowManager(
            $app->make(NodeKindRegistry::class),
            $app->make(ExecutorRegistry::class),
            $app->make(Dispatcher::class),
            (array) $app['config']['fancy-flow'],
        ));
        $this->app->alias(FancyFlowManager::class, 'fancy-flow');
    }

    public function boot(): void
    {
        $this->wireCapabilities();

        $migrations = dirname(__DIR__, 2).'/database/migrations';

        // Only create the tables when persistence is on; publishing is always
        // available so apps can vendor + tweak the migrations.
        if ((bool) ($this->app['config']['fancy-flow']['persistence']['enabled'] ?? false)) {
            $this->loadMigrationsFrom($migrations);
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([self::CONFIG => $this->app->configPath('fancy-flow.php')], 'fancy-flow-config');
            $this->publishes([$migrations => $this->app->databasePath('migrations')], 'fancy-flow-migrations');
            $this->commands([
                RunFlowCommand::class,
                ListKindsCommand::class,
                ValidateFlowCommand::class,
                DiscoverCommand::class,
            ]);
        }

        if ((bool) ($this->app['config']['fancy-flow']['agentic'] ?? true)) {
            FlowRoutes::macro();
        }

        $this->discoverFlowNodes();
    }

    /**
     * Hand the container's capability bindings to the framework-free core.
     *
     * The core can't reach into a container, so the bridge is explicit: an app
     * that binds {@see RouteLlmClient} or {@see WorkflowResolver} gets it used
     * by `llm_router` / `subflow`. Nothing bound means `llm_router` falls back
     * to AUTO-DETECTING an installed adapter (Prism, laravel/ai) from the
     * `fancy-flow.llm` config.
     */
    private function wireCapabilities(): void
    {
        $config = (array) ($this->app['config']['fancy-flow']['llm'] ?? []);
        Capabilities::configureLlm(array_filter($config, static fn ($v) => $v !== null && $v !== ''));

        // Proxies, not instances: resolving the real services here would build
        // them before the app has finished configuring the container (and once
        // dragged the NodeKindRegistry into existence too early).
        if ($this->app->bound(RouteLlmClient::class)) {
            Capabilities::setLlmClient(new ContainerLlmClient($this->app));
        }

        if ($this->app->bound(WorkflowResolver::class)) {
            Capabilities::setWorkflowResolver(new ContainerWorkflowResolver($this->app));
        }
    }

    /** @param array<string,mixed> $config */
    private function buildDeps(Container $app, array $config): ExecutorDeps
    {
        $cache = $app->make(CacheFactory::class)->store();
        $prefix = (string) ($config['store_prefix'] ?? 'fancy_flow:');

        return new ExecutorDeps(
            http: $app->make(HttpClient::class),
            llm: $app->make(LlmClient::class),
            tools: $app->make(ToolInvoker::class),
            vectors: $app->make(VectorStore::class),
            notifier: $app->make(Notifier::class),
            memory: new CacheStore($cache, $prefix.'memory:'),
            data: new CacheStore($cache, $prefix.'data:'),
        );
    }

    private function discoverFlowNodes(): void
    {
        $paths = (array) ($this->app['config']['fancy-flow']['discover'] ?? []);
        if ($paths === []) {
            return;
        }

        $kinds = $this->app->make(NodeKindRegistry::class);
        $executors = $this->app->make(ExecutorRegistry::class);
        foreach (FlowNodeDiscovery::scan($paths) as $entry) {
            $attr = $entry['attribute'];
            $kinds->register(NodeKind::fromArray($attr->toKindArray()));
            $executors->bind($attr->name, $entry['class']);
        }
    }
}
