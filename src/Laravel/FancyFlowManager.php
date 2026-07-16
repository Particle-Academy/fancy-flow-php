<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use Closure;
use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Laravel\Events\NodeOutput;
use FancyFlow\Laravel\Events\NodeStatusChanged;
use FancyFlow\Laravel\Events\WorkflowFailed;
use FancyFlow\Laravel\Events\WorkflowFinished;
use FancyFlow\Laravel\Events\WorkflowStarted;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Runtime\RunOptions;
use FancyFlow\Runtime\RunResult;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\ImportResult;
use FancyFlow\Workflow;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * The Laravel-facing API for fancy-flow (resolved as `fancy-flow`, fronted by
 * the {@see \FancyFlow\Laravel\Facades\FancyFlow} facade). Wraps the
 * framework-free core with container-resolved executors, the shared kind
 * registry, and a RunEvent → Laravel-events bridge.
 */
final class FancyFlowManager
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly NodeKindRegistry $kinds,
        private readonly ExecutorRegistry $executors,
        private readonly Dispatcher $events,
        private readonly array $config = [],
    ) {}

    public function kinds(): NodeKindRegistry
    {
        return $this->kinds;
    }

    public function executors(): ExecutorRegistry
    {
        return $this->executors;
    }

    /** Import a WorkflowSchema (JSON string or array) against the app's kind registry. */
    public function import(string|array $schema, bool $lenient = false): ImportResult
    {
        return Workflow::import($schema, lenient: $lenient, registry: $this->kinds);
    }

    /** Register a node kind (a {@see NodeKind} or its array form). */
    public function registerKind(NodeKind|array $kind): static
    {
        $this->kinds->register($kind instanceof NodeKind ? $kind : NodeKind::fromArray($kind));

        return $this;
    }

    /**
     * Bind an executor to a kind, optionally registering the kind definition in
     * the same call. The executor may be a class-string (container-resolved),
     * callable, or {@see NodeExecutor}.
     */
    public function extend(
        string $kind,
        callable|NodeExecutor|string $executor,
        NodeKind|array|null $definition = null,
    ): static {
        if ($definition !== null) {
            $this->registerKind($definition);
        }
        $this->executors->bind($kind, $executor);

        return $this;
    }

    /**
     * Run a flow (a graph, an import result, or a WorkflowSchema) and bridge its
     * events to Laravel events. Returns the {@see RunResult}.
     *
     * @param array<string,array<string,mixed>> $initialInputs
     * @param (callable(RunEvent):void)|null    $onEvent
     */
    public function run(
        FlowGraph|ImportResult|string|array $flow,
        array $initialInputs = [],
        ?callable $onEvent = null,
        ?RunOptions $options = null,
        ?string $runId = null,
        ?ExecutorRegistry $executors = null,
        bool $emitTerminalEvents = true,
    ): RunResult {
        $graph = $this->toGraph($flow);
        $runId ??= self::newRunId();

        $options ??= new RunOptions(
            timeoutMs: $this->config['timeout_ms'] ?? null,
            initialInputs: $initialInputs,
        );

        $result = (new FlowRunner())->run($graph, $executors ?? $this->executors, $this->bridge($runId, $onEvent), $options);

        // The durable job owns its terminal events (a pause is not a failure), so
        // it opts out here and dispatches WorkflowFinished itself on completion.
        if ($emitTerminalEvents && $this->eventsEnabled()) {
            $this->events->dispatch(
                $result->ok
                    ? new WorkflowFinished($runId, true, $result->outputs)
                    : new WorkflowFailed($runId, $result->error ?? 'error'),
            );
        }

        return $result;
    }

    /**
     * Create a persisted {@see \FancyFlow\Laravel\Models\WorkflowRun} and queue
     * it via {@see \FancyFlow\Laravel\Jobs\RunWorkflowJob} — a durable, resumable
     * run. Requires `persistence.enabled` + the migrations.
     *
     * @param array<string,array<string,mixed>> $initialInputs
     */
    public function dispatch(
        FlowGraph|ImportResult|string|array $flow,
        array $initialInputs = [],
        ?int $workflowId = null,
    ): \FancyFlow\Laravel\Models\WorkflowRun {
        $run = new \FancyFlow\Laravel\Models\WorkflowRun();
        $run->forceFill([
            'run_key' => self::newRunId(),
            'workflow_id' => $workflowId,
            'status' => \FancyFlow\Laravel\Models\WorkflowRun::PENDING,
            'schema' => $this->toSchemaArray($flow),
            'initial_inputs' => $initialInputs,
        ])->save();

        \FancyFlow\Laravel\Jobs\RunWorkflowJob::enqueue($run);

        return $run;
    }

    /** @return array<string,mixed> */
    private function toSchemaArray(FlowGraph|ImportResult|string|array $flow): array
    {
        if (is_string($flow)) {
            $decoded = json_decode($flow, true);

            return is_array($decoded) ? $decoded : [];
        }
        if (is_array($flow)) {
            return $flow;
        }

        return Workflow::export($flow instanceof ImportResult ? $flow->graph : $flow);
    }

    public function toGraph(FlowGraph|ImportResult|string|array $flow): FlowGraph
    {
        if ($flow instanceof FlowGraph) {
            return $flow;
        }
        if ($flow instanceof ImportResult) {
            return $flow->graph;
        }

        return $this->import($flow, lenient: true)->graph;
    }

    public static function newRunId(): string
    {
        return 'run_'.bin2hex(random_bytes(8));
    }

    /**
     * Wrap the caller's onEvent so each RunEvent also fires the matching Laravel
     * event. WorkflowFinished/Failed are dispatched from {@see run()} instead (they
     * carry the full outputs, which the stream event does not).
     *
     * @param (callable(RunEvent):void)|null $onEvent
     */
    private function bridge(string $runId, ?callable $onEvent): Closure
    {
        $dispatch = $this->eventsEnabled() ? $this->events : null;

        return function (RunEvent $event) use ($runId, $onEvent, $dispatch): void {
            if ($onEvent !== null) {
                $onEvent($event);
            }
            if ($dispatch === null) {
                return;
            }
            match ($event->type) {
                RunEvent::RUN_START => $dispatch->dispatch(new WorkflowStarted($runId)),
                RunEvent::NODE_STATUS => $dispatch->dispatch(
                    new NodeStatusChanged($runId, (string) $event->nodeId, (string) $event->status, $event->text),
                ),
                RunEvent::NODE_OUTPUT => $dispatch->dispatch(
                    new NodeOutput($runId, (string) $event->nodeId, (string) $event->portId, $event->value),
                ),
                default => null,
            };
        };
    }

    private function eventsEnabled(): bool
    {
        return (bool) ($this->config['events'] ?? true);
    }
}
