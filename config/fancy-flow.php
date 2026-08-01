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
    | Queue (durable runs — 0.3, per-node jobs — 0.10)
    |--------------------------------------------------------------------------
    | `driver` decides how a run is carried on the queue.
    |
    |   "single"    one job for the whole graph. The checkpoint is written when
    |               that job returns — so a worker killed mid-run (timeout,
    |               deploy, OOM) checkpoints nothing, and the retry re-runs every
    |               node that had already completed.
    |
    |   "per_node"  one job per node. Each node's output is written as it
    |               finishes, claimed through a unique constraint so two workers
    |               can never run the same node. A killed worker loses at most
    |               the node that was in flight; independent branches run in
    |               parallel on separate workers; retries become per node, so a
    |               kind declaring `sideEffects: unsafe-to-replay` gets exactly
    |               one attempt while a flaky HTTP node can have several.
    |
    | "single" remains the default so upgrading changes nothing. Switching is a
    | config change: every entry point routes through RunWorkflowJob::enqueue().
    | "per_node" needs the workflow_run_nodes migration.
    */
    'queue' => [
        'driver' => env('FANCY_FLOW_QUEUE_DRIVER', 'single'),
        'connection' => env('FANCY_FLOW_QUEUE_CONNECTION'),
        'queue' => env('FANCY_FLOW_QUEUE', 'default'),

        // Attempts per job: the whole run under "single", one node under
        // "per_node".
        'tries' => 1,
        'backoff' => 0,

        // Per-kind attempt overrides, for "per_node" only. Keyed by kind id
        // (bare or namespaced — both resolve). A kind declaring
        // `sideEffects: unsafe-to-replay` is pinned to 1 regardless: the node
        // has said a second attempt is not a repeat of the first.
        'node_tries' => [
            // 'api_request' => 3,
        ],

        // How many extra nodes one "per_node" job may run inline before handing
        // back to the queue. A round trip per node is real overhead, and a chain
        // of fast nodes is mostly overhead — draining collapses such a chain
        // back into one job. It only ever drains where the next step is
        // unambiguous: exactly one ready successor, single-attempt, no human
        // wait. Fan-out always dispatches, so parallelism is never traded away.
        //
        // OFF by default: draining trades a little of the durability the driver
        // exists to provide for latency, and that should be chosen rather than
        // inherited.
        'drain_limit' => 0,
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigger guards (0.9)
    |--------------------------------------------------------------------------
    | When one event fires several workflows, dispatch them together with
    | `FancyFlow::dispatchCohort()` instead of a loop over `dispatch()`. A cohort
    | runs them in the order you declared, one at a time, and re-checks a named
    | guard immediately before each — so a workflow that deletes the triggering
    | record leaves the others SKIPPED with a recorded reason, rather than
    | completing "successfully" over state that is no longer there.
    |
    | Each entry maps a guard name to a class implementing
    | FancyFlow\Contracts\TriggerGuard. Names are resolved through the container
    | at run time (never serialized), so constructor DI works.
    */
    'guards' => [
        // 'record-exists' => \App\Flow\Guards\RecordStillExists::class,
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
