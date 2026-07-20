<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Structural kinds
    |--------------------------------------------------------------------------
    | Register the structural `note` + `subgraph` kinds alongside the 22
    | built-ins so imported editor graphs that contain them validate cleanly.
    */
    'structural_kinds' => true,

    /*
    |--------------------------------------------------------------------------
    | Custom kinds + executors
    |--------------------------------------------------------------------------
    | `kinds` are extra NodeKind definitions (NodeKind::fromArray shape).
    | `executors` bind a kind name to an executor: a class-string (resolved
    | through the container, so constructor DI works), a callable, or a
    | NodeExecutor instance. Prefer the #[FlowNode] attribute + `discover` for
    | co-located kind+executor pairs.
    */
    'kinds' => [
        // ['name' => 'geocode', 'category' => 'io', 'label' => 'Geocode', 'configSchema' => [...]],
    ],

    'executors' => [
        // 'geocode' => \App\Flow\GeocodeExecutor::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Discovery
    |--------------------------------------------------------------------------
    | Directories scanned by `flow:discover` for executor classes carrying the
    | #[FlowNode] attribute. Each registers BOTH its kind and its executor.
    */
    'discover' => [
        // app_path('Flow'),
    ],

    /*
    |--------------------------------------------------------------------------
    | LLM (the `llm_router` capability)
    |--------------------------------------------------------------------------
    | `llm_router` is a shuttle, not an engine: it carries the declared routes
    | out to an LLM client and carries the choice back. fancy-flow ships working
    | adapters for prism-php/prism and laravel/ai, and AUTO-DETECTS whichever
    | you have installed — no glue required.
    |
    | `driver`   only needed when BOTH libraries are installed (fancy-flow will
    |            not choose for you): "prism" or "laravel-ai".
    | `provider` / `model` defaults for nodes that don't set their own.
    |
    | Using something else? Implement FancyFlow\Capabilities\LlmClient and bind
    | it in the container — an explicit binding always wins over auto-detection.
    */
    'llm' => [
        'driver' => env('FANCY_FLOW_LLM_DRIVER'),
        'provider' => env('FANCY_FLOW_LLM_PROVIDER'),
        'model' => env('FANCY_FLOW_LLM_MODEL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runs
    |--------------------------------------------------------------------------
    */
    'timeout_ms' => null,

    // Dispatch the RunEvent-derived Laravel events (WorkflowStarted, …). When a
    // consumer marks them ShouldBroadcast, this feeds <FlowEditor> live status.
    'events' => true,

    // Register the `agent` kind (LLM agent with tools + multi-step reasoning)
    // and the Route::flow() webhook macro.
    'agentic' => true,

    /*
    |--------------------------------------------------------------------------
    | Queue (durable runs — 0.3)
    |--------------------------------------------------------------------------
    */
    'queue' => [
        'connection' => env('FANCY_FLOW_QUEUE_CONNECTION'),
        'queue' => env('FANCY_FLOW_QUEUE', 'default'),
        'tries' => 1,
        'backoff' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistence (0.3)
    |--------------------------------------------------------------------------
    | When enabled, publishable migrations create the Workflow + WorkflowRun
    | tables and RunWorkflowJob persists per-node outputs so a crashed worker
    | resumes from the last completed node.
    */
    'persistence' => [
        'enabled' => false,
        'table_prefix' => 'fancy_flow_',
    ],

    /*
    |--------------------------------------------------------------------------
    | Store namespace
    |--------------------------------------------------------------------------
    | Cache key prefix backing the memory_store / data_store default executors.
    */
    'store_prefix' => 'fancy_flow:',

];
