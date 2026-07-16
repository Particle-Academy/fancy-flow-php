<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Http;

use FancyFlow\Laravel\FancyFlowManager;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

/**
 * The webhook trigger entry point. Registers a `Route::flow()` macro that
 * exposes a workflow as an HTTP endpoint — an inbound request seeds the trigger
 * nodes with its payload and dispatches a (durable, when persistence is on) run:
 *
 *     Route::flow('/hooks/onboard', $schema);           // POST → dispatch a run
 *     Route::flow('/hooks/sync', $schema, ['durable' => false]); // run inline
 */
final class FlowRoutes
{
    public static function macro(): void
    {
        if (Route::hasMacro('flow')) {
            return;
        }

        Route::macro('flow', function (string $uri, array|string $schema, array $options = []) {
            /** @var Router $this */
            return $this->post($uri, fn (Request $request) => FlowRoutes::handle($request, $schema, $options));
        });
    }

    /**
     * @param array<string,mixed>|string $schema
     * @param array<string,mixed>        $options
     */
    public static function handle(Request $request, array|string $schema, array $options): mixed
    {
        /** @var FancyFlowManager $flow */
        $flow = app('fancy-flow');

        // Seed every trigger node with the request payload.
        $inputs = [];
        foreach ($flow->toGraph($schema)->nodes as $node) {
            if (str_ends_with((string) $node->type, '_trigger')) {
                $inputs[$node->id] = ['payload' => $request->all()];
            }
        }

        $durable = $options['durable'] ?? (bool) config('fancy-flow.persistence.enabled', false);

        if ($durable) {
            $run = $flow->dispatch($schema, $inputs);

            return response()->json(['run_key' => $run->run_key, 'status' => $run->status], 202);
        }

        $result = $flow->run($schema, $inputs);

        return response()->json(['ok' => $result->ok, 'outputs' => $result->outputs], $result->ok ? 200 : 422);
    }
}
