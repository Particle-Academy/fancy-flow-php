<?php

declare(strict_types=1);

namespace FancyFlow\Registry;

use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Nodes\Ai\EmbedSearchExecutor;
use FancyFlow\Nodes\Ai\LlmCallExecutor;
use FancyFlow\Nodes\Ai\LlmRouterExecutor;
use FancyFlow\Nodes\Ai\ToolUseExecutor;
use FancyFlow\Nodes\Data\DataStoreExecutor;
use FancyFlow\Nodes\Data\MemoryStoreExecutor;
use FancyFlow\Nodes\Data\VariableExecutor;
use FancyFlow\Nodes\Human\HumanApprovalExecutor;
use FancyFlow\Nodes\Human\NotifyExecutor;
use FancyFlow\Nodes\Human\UserInputExecutor;
use FancyFlow\Nodes\Io\ApiRequestExecutor;
use FancyFlow\Nodes\Io\WebhookOutExecutor;
use FancyFlow\Nodes\Logic\BranchExecutor;
use FancyFlow\Nodes\Logic\ForEachExecutor;
use FancyFlow\Nodes\Logic\MergeExecutor;
use FancyFlow\Nodes\Logic\SwitchCaseExecutor;
use FancyFlow\Nodes\Logic\TransformExecutor;
use FancyFlow\Nodes\Logic\WaitExecutor;
use FancyFlow\Nodes\Output\LogExecutor;
use FancyFlow\Nodes\Output\OutputExecutor;
use FancyFlow\Nodes\Structural\SubflowExecutor;
use FancyFlow\Nodes\Structural\SubgraphExecutor;
use FancyFlow\Nodes\Support\ExecutorDeps;
use FancyFlow\Nodes\Trigger\ManualTriggerExecutor;
use FancyFlow\Nodes\Trigger\ScheduleTriggerExecutor;
use FancyFlow\Nodes\Trigger\WebhookTriggerExecutor;

/**
 * The built-in node library — the 22 kinds across 7 domains that
 * `@particle-academy/fancy-flow` ships, ported kind-for-kind, plus batteries-
 * included framework-free executors.
 *
 *   Builtin::register($registry);          // install the 22 kind definitions
 *   $executors = Builtin::executors();     // default executors (fake clients)
 *
 * On the TS side the built-in kinds ship *without* executors (each host wires
 * where memory / HTTP / AI actually go). The PHP twin ships default executors
 * so a flow runs out of the box, while every one stays overridable — the same
 * kind + executor path a custom node uses. Inject real clients via
 * {@see ExecutorDeps}; the 0.2 Laravel layer binds them to the container.
 */
final class Builtin
{
    /** Install every built-in kind definition into a registry (default: the shared one). */
    public static function register(?NodeKindRegistry $registry = null, bool $withStructural = false): NodeKindRegistry
    {
        $registry ??= NodeKindRegistry::default();
        foreach (self::kinds() as $raw) {
            $registry->register(NodeKind::fromArray($raw));
        }
        if ($withStructural) {
            foreach (self::structuralKinds() as $raw) {
                $registry->register(NodeKind::fromArray($raw));
            }
        }

        return $registry;
    }

    /**
     * A registry pre-bound with the default executor for every built-in kind.
     * Pass {@see ExecutorDeps} to inject real HTTP / LLM / store / notifier
     * clients; omit it for the deterministic framework-free fakes.
     */
    public static function executors(?ExecutorDeps $deps = null, ?\FancyFlow\Contracts\Resolver $resolver = null): ExecutorRegistry
    {
        $deps ??= new ExecutorDeps();

        // Bound under CANONICAL ids. Lookup is alias-aware in both directions
        // (see ExecutorRegistry::resolveFor), so a graph saved with bare `branch`
        // still finds this, and a host binding under bare `branch` still wins.
        $bindings = [
            // triggers
            'manual_trigger' => new ManualTriggerExecutor(),
            'webhook_trigger' => new WebhookTriggerExecutor(),
            'schedule_trigger' => new ScheduleTriggerExecutor(),
            // human
            'user_input' => new UserInputExecutor(),
            'human_approval' => new HumanApprovalExecutor(),
            'notify' => new NotifyExecutor($deps->notifier),
            // logic
            'branch' => new BranchExecutor(),
            'switch_case' => new SwitchCaseExecutor(),
            'for_each' => new ForEachExecutor(),
            'merge' => new MergeExecutor(),
            'wait' => new WaitExecutor(),
            'transform' => new TransformExecutor(),
            'subflow' => new SubflowExecutor($deps),
            // data
            'memory_store' => new MemoryStoreExecutor($deps->memory),
            'data_store' => new DataStoreExecutor($deps->data),
            'variable' => new VariableExecutor(),
            // ai
            'llm_call' => new LlmCallExecutor($deps->llm),
            'llm_router' => new LlmRouterExecutor(),
            'tool_use' => new ToolUseExecutor($deps->tools),
            'embed_search' => new EmbedSearchExecutor($deps->vectors),
            // io
            'api_request' => new ApiRequestExecutor($deps->http),
            'webhook_out' => new WebhookOutExecutor($deps->http),
            // output
            'output' => new OutputExecutor(),
            'log' => new LogExecutor(),
            // structural
            'subgraph' => new SubgraphExecutor($deps),
        ];

        // Bind each executor under EVERY id its kind answers to, not just the
        // canonical one. Convention-derived variants (bare ↔ `@particle-academy/`)
        // are not enough: `llm_router` was renamed from `llm_branch`, and no
        // amount of prefix arithmetic gets you from one to the other — only the
        // kind's declared alias list does. Mirrors the TS `kindIds()` rule that
        // anything keyed by kind name must key on all of them.
        $ids = self::kindIdIndex();

        $expanded = [];
        foreach ($bindings as $kind => $executor) {
            foreach ($ids[$kind] ?? [KindId::canonical($kind)] as $id) {
                $expanded[$id] = $executor;
            }
        }

        return (new ExecutorRegistry($resolver))->bindMany($expanded);
    }

    /**
     * bare kind name → every id that kind answers to (canonical first).
     *
     * @return array<string, list<string>>
     */
    private static function kindIdIndex(): array
    {
        $index = [];
        foreach ([...self::kinds(), ...self::structuralKinds(), self::agentKind()] as $raw) {
            $name = (string) $raw['name'];
            $index[KindId::bare($name)] = array_values(array_unique([$name, ...($raw['aliases'] ?? [])]));
        }

        return $index;
    }

    /**
     * Give a built-in kind literal its CANONICAL namespaced id, keeping every
     * previous spelling as an alias.
     *
     * The literals below are written with bare names because that reads better
     * and there are 24 of them; namespacing is applied here so no kind can drift
     * out of the convention by hand. Parity with fancy-flow 0.11.0, where each
     * kind is `@particle-academy/<name>` with `aliases: ["<name>", "@fancy/<name>"]`.
     *
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private static function canonicalize(array $raw): array
    {
        $bare = KindId::bare((string) $raw['name']);
        $raw['name'] = KindId::NAMESPACE.$bare;
        $raw['aliases'] = array_values(array_unique([
            ...KindId::builtinAliases($bare),
            ...(is_array($raw['aliases'] ?? null) ? $raw['aliases'] : []),
        ]));

        return $raw;
    }

    /**
     * The raw kind literals — a direct port of fancy-flow's `builtin.ts` KINDS,
     * returned with canonical namespaced ids (+ bare aliases).
     *
     * @return list<array<string,mixed>>
     */
    public static function kinds(): array
    {
        return array_map(self::canonicalize(...), self::kindLiterals());
    }

    /**
     * @return list<array<string,mixed>>
     */
    private static function kindLiterals(): array
    {
        $httpMethod = [
            'type' => 'select', 'key' => 'method', 'label' => 'Method', 'default' => 'GET', 'required' => true,
            'options' => [
                ['value' => 'GET', 'label' => 'GET'],
                ['value' => 'POST', 'label' => 'POST'],
                ['value' => 'PUT', 'label' => 'PUT'],
                ['value' => 'PATCH', 'label' => 'PATCH'],
                ['value' => 'DELETE', 'label' => 'DELETE'],
            ],
        ];

        return [
            // ───────────── Triggers ─────────────
            [
                'name' => 'manual_trigger', 'category' => 'trigger', 'label' => 'Manual',
                'description' => 'Entry point fired when the user clicks Run.', 'icon' => '⚡',
                'inputs' => [], 'outputs' => [['id' => 'out']],
            ],
            [
                'name' => 'webhook_trigger', 'category' => 'trigger', 'label' => 'Webhook',
                'description' => 'Triggered by an inbound HTTP request to a host-provided URL.', 'icon' => '📡',
                'inputs' => [], 'outputs' => [['id' => 'out', 'label' => 'payload']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'path', 'label' => 'Path', 'placeholder' => '/hooks/my-flow', 'required' => true],
                    ['type' => 'select', 'key' => 'method', 'label' => 'Method', 'default' => 'POST', 'options' => [
                        ['value' => 'POST', 'label' => 'POST'], ['value' => 'GET', 'label' => 'GET'],
                    ]],
                    ['type' => 'credential', 'key' => 'secret', 'label' => 'Verifying secret', 'credentialType' => 'webhook_secret'],
                ],
            ],
            [
                'name' => 'schedule_trigger', 'category' => 'trigger', 'label' => 'Schedule',
                'description' => 'Fires on a cron schedule (host-implemented).', 'icon' => '⏱',
                'inputs' => [], 'outputs' => [['id' => 'out']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'cron', 'label' => 'Cron', 'placeholder' => '*/5 * * * *', 'required' => true,
                        'description' => 'Standard 5-field cron expression.'],
                    ['type' => 'text', 'key' => 'timezone', 'label' => 'Timezone', 'placeholder' => 'UTC', 'default' => 'UTC'],
                ],
            ],
            [
                'name' => 'user_input', 'category' => 'human', 'label' => 'User Input',
                'description' => 'Pause the flow until the user submits the configured form.', 'icon' => '✎',
                'pausesForHuman' => 'input',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out', 'label' => 'values']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'title', 'label' => 'Form title', 'default' => 'Need your input'],
                    ['type' => 'json', 'key' => 'fields', 'label' => 'Fields (JSON)', 'language' => 'json', 'rows' => 6,
                        'default' => [['key' => 'answer', 'label' => 'Your answer', 'type' => 'textarea']]],
                ],
            ],

            // ───────────── Logic ─────────────
            [
                'name' => 'branch', 'category' => 'logic', 'label' => 'Branch',
                'description' => 'Multi-way branch on a condition or value.', 'icon' => '◇',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'true', 'label' => 'true'], ['id' => 'false', 'label' => 'false']],
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'condition', 'label' => 'Condition', 'example' => '{{ $json.active }}', 'required' => true],
                ],
            ],
            [
                'name' => 'switch_case', 'category' => 'logic', 'label' => 'Switch',
                'description' => 'Route to one of N labelled outputs based on a key.', 'icon' => '⤳',
                'inputs' => [['id' => 'in']],
                'outputs' => [['id' => 'case_a', 'label' => 'a'], ['id' => 'case_b', 'label' => 'b'], ['id' => 'default', 'label' => 'default']],
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Switch on', 'example' => '{{ $json.kind }}', 'required' => true],
                    ['type' => 'json', 'key' => 'cases', 'label' => 'Cases (JSON)', 'default' => ['a' => 'case_a', 'b' => 'case_b']],
                ],
            ],
            [
                'name' => 'for_each', 'category' => 'logic', 'label' => 'For Each',
                'description' => 'Iterate over a list, emitting each item on `item`.', 'icon' => '↻',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'item', 'label' => 'item'], ['id' => 'done', 'label' => 'done']],
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'source', 'label' => 'List', 'example' => '{{ $json.users }}', 'required' => true],
                    ['type' => 'number', 'key' => 'concurrency', 'label' => 'Concurrency', 'default' => 1, 'min' => 1, 'max' => 50],
                ],
            ],
            [
                'name' => 'subflow', 'category' => 'logic', 'label' => 'SubFlow',
                'description' => 'Run another workflow and bring its result — or its live progress — back into this one.',
                'icon' => '⧉',
                'inputs' => [['id' => 'in']],
                // The `stream` port only exists when something streams; see
                // SubflowExecutor::ports() for the config-derived set.
                'outputs' => [['id' => 'out', 'label' => 'result']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'workflow', 'label' => 'Workflow', 'required' => true,
                        'placeholder' => 'onboarding-v2',
                        'description' => "Reference resolved by the host's WorkflowResolver."],
                    ['type' => 'number', 'key' => 'version', 'label' => 'Pin to version',
                        'description' => 'Optional. Leave blank to always run the child current version. '
                            .'Pinning fails the run loudly if the child has moved on. Without it, someone '
                            .'edits the child and this flow silently runs different logic.'],
                    ['type' => 'select', 'key' => 'mode', 'label' => 'Return', 'default' => 'output', 'options' => [
                        ['value' => 'output', 'label' => 'Output when it finishes'],
                        ['value' => 'stream', 'label' => 'Stream progress as it runs'],
                        ['value' => 'both', 'label' => 'Both — stream, then output'],
                    ], 'description' => 'Streaming adds a second port so a parent can show progress instead of a spinner.'],
                    ['type' => 'json', 'key' => 'inputs', 'label' => 'Input mapping',
                        'description' => "Entry-point inputs for the child run. Omit to pass this node's inputs straight through."],
                    ['type' => 'number', 'key' => 'maxDepth', 'label' => 'Max nesting depth',
                        'default' => SubflowExecutor::DEFAULT_MAX_DEPTH, 'min' => 1, 'max' => 32,
                        'description' => 'Guards against a workflow referencing itself.'],
                ],
            ],
            [
                'name' => 'merge', 'category' => 'logic', 'label' => 'Merge',
                'description' => 'Combine multiple inputs into one object or array.', 'icon' => '⊕',
                'inputs' => [['id' => 'a'], ['id' => 'b']], 'outputs' => [['id' => 'out']],
                'configSchema' => [
                    ['type' => 'select', 'key' => 'mode', 'label' => 'Mode', 'default' => 'merge',
                        'options' => [['value' => 'merge', 'label' => 'Object merge'], ['value' => 'concat', 'label' => 'Array concat']]],
                ],
            ],
            [
                'name' => 'wait', 'category' => 'logic', 'label' => 'Wait',
                'description' => 'Sleep or wait for an external event.', 'icon' => '⏸',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'mode', 'label' => 'Mode', 'default' => 'duration',
                        'options' => [['value' => 'duration', 'label' => 'Duration'], ['value' => 'until', 'label' => 'Until timestamp'], ['value' => 'event', 'label' => 'External event']]],
                    ['type' => 'text', 'key' => 'duration', 'label' => 'Duration', 'placeholder' => '5s, 10m, 1h', 'description' => 'Used when mode = duration.'],
                ],
            ],
            [
                'name' => 'transform', 'category' => 'logic', 'label' => 'Transform',
                'description' => 'Reshape data with an expression.', 'icon' => 'ƒ',
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'expression', 'label' => 'Expression',
                        'example' => "{{ { id: \$json.id, name: \$json.first + ' ' + \$json.last } }}", 'required' => true],
                ],
            ],

            // ───────────── Data ─────────────
            [
                'name' => 'memory_store', 'category' => 'data', 'label' => 'Memory Store',
                'description' => 'Read or write per-conversation memory.', 'icon' => '🧠',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'operation', 'label' => 'Operation', 'required' => true, 'default' => 'read',
                        'options' => [['value' => 'read', 'label' => 'Read'], ['value' => 'write', 'label' => 'Write'], ['value' => 'append', 'label' => 'Append']]],
                    ['type' => 'text', 'key' => 'key', 'label' => 'Key', 'placeholder' => 'user.preferences', 'required' => true],
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Value (write/append only)', 'example' => '{{ $json }}'],
                    ['type' => 'credential', 'key' => 'store', 'label' => 'Memory store', 'credentialType' => 'memory_store'],
                ],
            ],
            [
                'name' => 'data_store', 'category' => 'data', 'label' => 'Data Store',
                'description' => 'Key-value or table read/write against a host store.', 'icon' => '🗃',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'operation', 'label' => 'Operation', 'required' => true, 'default' => 'get',
                        'options' => [
                            ['value' => 'get', 'label' => 'Get'], ['value' => 'set', 'label' => 'Set'], ['value' => 'delete', 'label' => 'Delete'],
                            ['value' => 'query', 'label' => 'Query'], ['value' => 'list', 'label' => 'List'],
                        ]],
                    ['type' => 'text', 'key' => 'table', 'label' => 'Table / collection', 'required' => true],
                    ['type' => 'text', 'key' => 'key', 'label' => 'Key'],
                    ['type' => 'json', 'key' => 'where', 'label' => 'Where (JSON)', 'description' => 'For query/list operations.'],
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Value (set only)', 'example' => '{{ $json }}'],
                    ['type' => 'credential', 'key' => 'store', 'label' => 'Data store', 'credentialType' => 'data_store'],
                ],
            ],
            [
                'name' => 'variable', 'category' => 'data', 'label' => 'Variable',
                'description' => 'Workflow-scoped value used by other nodes.', 'icon' => '𝓍',
                'configSchema' => [
                    ['type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true],
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Value', 'required' => true],
                ],
            ],

            // ───────────── AI ─────────────
            [
                'name' => 'llm_call', 'category' => 'ai', 'label' => 'LLM Call',
                'description' => 'Send a prompt + context to a model and receive a response.', 'icon' => '✦',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'provider', 'label' => 'Provider', 'default' => 'anthropic',
                        'options' => [
                            ['value' => 'anthropic', 'label' => 'Anthropic'],
                            ['value' => 'openai', 'label' => 'OpenAI'],
                            ['value' => 'custom', 'label' => 'Custom'],
                        ]],
                    ['type' => 'text', 'key' => 'model', 'label' => 'Model', 'placeholder' => 'claude-sonnet-4-5', 'required' => true],
                    ['type' => 'textarea', 'key' => 'system', 'label' => 'System prompt', 'rows' => 4],
                    ['type' => 'expression', 'key' => 'prompt', 'label' => 'User prompt', 'example' => '{{ $json.question }}', 'required' => true],
                    ['type' => 'number', 'key' => 'temperature', 'label' => 'Temperature', 'min' => 0, 'max' => 2, 'step' => 0.1, 'default' => 0.7],
                    ['type' => 'number', 'key' => 'max_tokens', 'label' => 'Max tokens', 'min' => 1, 'max' => 8192, 'default' => 1024],
                    ['type' => 'json', 'key' => 'tools', 'label' => 'Tools (JSON)', 'description' => 'Optional Anthropic-style tool definitions.'],
                    ['type' => 'credential', 'key' => 'credential', 'label' => 'API credential', 'credentialType' => 'llm_credential'],
                ],
            ],
            [
                'name' => 'llm_router', 'category' => 'ai', 'label' => 'LLM Router',
                // Renamed from `llm_branch`: the node picks one of N NAMED
                // ROUTES, it is not a two-way branch, and the id now matches the
                // label and the `routes[]` config. Every previously-shipped id
                // stays an alias, so graphs and documents already carrying
                // `llm_branch` keep resolving. Config keys are unchanged.
                'aliases' => ['llm_branch', '@fancy/llm_branch'],
                'description' => 'Let a model choose which route the flow takes.', 'icon' => '✧',
                'inputs' => [['id' => 'in']],
                // Each declared route is a port; the executor returns
                // Port::only($id) to pick one. The static ports here are the
                // DEFAULT-config shape — real ports come from the node's own
                // `routes` via LlmRouterExecutor::ports(), the twin of the TS
                // kind's `outputs: (config) => routePorts(...)`.
                'outputs' => [['id' => 'a', 'label' => 'a'], ['id' => 'b', 'label' => 'b'], ['id' => 'fallback', 'label' => 'fallback']],
                'configSchema' => [
                    ['type' => 'textarea', 'key' => 'system', 'label' => 'System prompt', 'rows' => 3,
                        'description' => 'Optional framing for the routing decision.'],
                    ['type' => 'expression', 'key' => 'prompt', 'label' => 'What to route on', 'required' => true,
                        'example' => '{{ $json.message }}'],
                    ['type' => 'json', 'key' => 'routes', 'label' => 'Routes',
                        'description' => 'The model picks exactly one. Descriptions are what it chooses between — make them distinct.',
                        'default' => [
                            ['port' => 'a', 'description' => 'Describe when the model should pick this route.'],
                            ['port' => 'b', 'description' => 'Describe when the model should pick this route.'],
                        ]],
                    ['type' => 'select', 'key' => 'provider', 'label' => 'Provider', 'default' => 'anthropic', 'options' => [
                        ['value' => 'anthropic', 'label' => 'Anthropic'],
                        ['value' => 'openai', 'label' => 'OpenAI'],
                        ['value' => 'custom', 'label' => 'Custom'],
                    ]],
                    ['type' => 'text', 'key' => 'model', 'label' => 'Model', 'placeholder' => 'claude-sonnet-4-5'],
                    ['type' => 'switch', 'key' => 'fallback', 'label' => 'Add a `fallback` port', 'default' => true,
                        'description' => 'Where the flow goes if the model returns no usable route.'],
                    ['type' => 'credential', 'key' => 'credential', 'label' => 'API credential', 'credentialType' => 'llm_credential'],
                ],
            ],
            [
                'name' => 'tool_use', 'category' => 'ai', 'label' => 'Tool Use',
                'description' => 'Hand control to a host-registered tool by name.', 'icon' => '🛠',
                'configSchema' => [
                    ['type' => 'text', 'key' => 'tool', 'label' => 'Tool name', 'placeholder' => 'search_index', 'required' => true],
                    ['type' => 'expression', 'key' => 'args', 'label' => 'Arguments', 'example' => '{{ { query: $json.q } }}'],
                ],
            ],
            [
                'name' => 'embed_search', 'category' => 'ai', 'label' => 'Embed & Search',
                'description' => 'Embed a query and search a vector store.', 'icon' => '✺',
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'query', 'label' => 'Query', 'required' => true, 'example' => '{{ $json.question }}'],
                    ['type' => 'number', 'key' => 'topK', 'label' => 'Top K', 'default' => 5, 'min' => 1, 'max' => 50],
                    ['type' => 'credential', 'key' => 'vectorStore', 'label' => 'Vector store', 'credentialType' => 'vector_store'],
                ],
            ],

            // ───────────── IO ─────────────
            [
                'name' => 'api_request', 'category' => 'io', 'label' => 'API Request',
                'description' => 'HTTP request to any URL.', 'icon' => '↔',
                'configSchema' => [
                    $httpMethod,
                    ['type' => 'text', 'key' => 'url', 'label' => 'URL', 'placeholder' => 'https://api.example.com/...', 'required' => true],
                    ['type' => 'json', 'key' => 'headers', 'label' => 'Headers', 'default' => ['content-type' => 'application/json']],
                    ['type' => 'json', 'key' => 'body', 'label' => 'Body'],
                    ['type' => 'credential', 'key' => 'auth', 'label' => 'Auth', 'credentialType' => 'api_credential'],
                ],
            ],
            [
                'name' => 'webhook_out', 'category' => 'io', 'label' => 'Send Webhook',
                'description' => 'POST a payload to a configured URL.', 'icon' => '↗',
                'configSchema' => [
                    ['type' => 'text', 'key' => 'url', 'label' => 'URL', 'required' => true],
                    ['type' => 'json', 'key' => 'headers', 'label' => 'Headers'],
                    ['type' => 'expression', 'key' => 'payload', 'label' => 'Payload', 'required' => true, 'example' => '{{ $json }}'],
                ],
            ],

            // ───────────── Human ─────────────
            [
                'name' => 'human_approval', 'category' => 'human', 'label' => 'Human Approval',
                'description' => 'Pause until a human approves or denies.', 'icon' => '✓',
                'pausesForHuman' => 'approval',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'approved', 'label' => 'approved'], ['id' => 'denied', 'label' => 'denied']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'title', 'label' => 'Approval title', 'default' => 'Approve action'],
                    ['type' => 'textarea', 'key' => 'description', 'label' => 'Description for approver', 'rows' => 3],
                    ['type' => 'credential', 'key' => 'channel', 'label' => 'Notify channel', 'credentialType' => 'notify_channel'],
                ],
            ],
            [
                'name' => 'notify', 'category' => 'human', 'label' => 'Notify',
                'description' => 'Send a message via Slack / email / SMS / etc.', 'icon' => '🔔',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'channel', 'label' => 'Channel', 'default' => 'slack',
                        'options' => [
                            ['value' => 'slack', 'label' => 'Slack'], ['value' => 'email', 'label' => 'Email'],
                            ['value' => 'sms', 'label' => 'SMS'], ['value' => 'discord', 'label' => 'Discord'],
                        ]],
                    ['type' => 'text', 'key' => 'to', 'label' => 'To', 'required' => true],
                    ['type' => 'expression', 'key' => 'message', 'label' => 'Message', 'required' => true, 'example' => '{{ $json.summary }}'],
                ],
            ],

            // ───────────── Output ─────────────
            [
                'name' => 'output', 'category' => 'output', 'label' => 'Output',
                'description' => "Terminal node — captures the workflow's result.", 'icon' => '●',
                'inputs' => [['id' => 'in']], 'outputs' => [],
            ],
            [
                'name' => 'log', 'category' => 'output', 'label' => 'Log',
                'description' => 'Send to the run feed.', 'icon' => '≡',
                'inputs' => [['id' => 'in']], 'outputs' => [],
                'configSchema' => [
                    ['type' => 'select', 'key' => 'level', 'label' => 'Level', 'default' => 'info',
                        'options' => [['value' => 'info', 'label' => 'info'], ['value' => 'warn', 'label' => 'warn'], ['value' => 'error', 'label' => 'error']]],
                    ['type' => 'expression', 'key' => 'message', 'label' => 'Message', 'required' => true, 'example' => '{{ $json }}'],
                ],
            ],
        ];
    }

    /**
     * Structural kinds handled specially by the engine — `note` is never
     * executed; `subgraph` runs a nested flow. Not part of the TS `builtin.ts`
     * registration, so they are opt-in (`register(..., withStructural: true)`).
     *
     * @return list<array<string,mixed>>
     */
    public static function structuralKinds(): array
    {
        return array_map(self::canonicalize(...), self::structuralKindLiterals());
    }

    /** @return list<array<string,mixed>> */
    private static function structuralKindLiterals(): array
    {
        return [
            [
                'name' => 'note', 'category' => 'custom', 'label' => 'Note',
                'description' => 'A canvas annotation. Never executed.', 'icon' => '🗒',
                'inputs' => [], 'outputs' => [],
            ],
            [
                'name' => 'subgraph', 'category' => 'custom', 'label' => 'Subgraph',
                'description' => 'Runs a nested workflow.', 'icon' => '▣',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out']],
                'configSchema' => [
                    ['type' => 'json', 'key' => 'graph', 'label' => 'Nested workflow (WorkflowSchema)'],
                ],
            ],
        ];
    }

    /**
     * The `agent` kind — an LLM agent with tools + bounded multi-step reasoning
     * (0.3). Not part of the fancy-flow `builtin.ts` mirror, so it is opt-in; the
     * Laravel layer registers it + binds {@see \FancyFlow\Nodes\Ai\AgentExecutor}.
     *
     * @return array<string,mixed>
     */
    public static function agentKind(): array
    {
        return self::canonicalize([
            'name' => 'agent', 'category' => 'ai', 'label' => 'Agent', 'icon' => '✦',
            'description' => 'LLM agent with tools + multi-step reasoning.',
            'configSchema' => [
                ['type' => 'text', 'key' => 'model', 'label' => 'Model', 'required' => true, 'placeholder' => 'claude-sonnet-4-5'],
                ['type' => 'textarea', 'key' => 'system', 'label' => 'System prompt', 'rows' => 4],
                ['type' => 'expression', 'key' => 'prompt', 'label' => 'Task', 'required' => true, 'example' => '{{ $json.task }}'],
                ['type' => 'json', 'key' => 'tools', 'label' => 'Tools (JSON)', 'description' => 'Tool definitions the agent may call.'],
                ['type' => 'number', 'key' => 'max_steps', 'label' => 'Max steps', 'default' => 3, 'min' => 1, 'max' => 20],
                ['type' => 'number', 'key' => 'temperature', 'label' => 'Temperature', 'min' => 0, 'max' => 2, 'step' => 0.1, 'default' => 0.7],
            ],
        ]);
    }
}
