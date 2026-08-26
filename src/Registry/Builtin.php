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
    /**
     * Bare kind name => every id that kind answers to.
     *
     * PUBLIC because an override has to agree with the bindings it is
     * overriding. `ExecutorRegistry::bind()` consults this so that replacing
     * `user_input` replaces it under all three ids, the way the base bindings
     * were made — the kind registry is not necessarily populated at bind time,
     * so it cannot be the only source (#4).
     *
     * @return array<string, list<string>>
     */
    public static function kindIdIndex(): array
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
                'name' => 'manual_trigger', 'category' => 'trigger', 'sideEffects' => 'none', 'label' => 'Manual',
                // ManualTriggerExecutor returns $ctx->inputs -- the raw MAP, not the
                // `in` port. Flat at an entry point, port-keyed the moment the node
                // has an inbound edge, which is why this is not `'input'`.
                'emits' => 'input-map-merged',
                'description' => 'Entry point fired when the user clicks Run.', 'icon' => '⚡',
                'inputs' => [], 'outputs' => [['id' => 'out']],
            ],
            [
                'name' => 'webhook_trigger', 'category' => 'trigger', 'sideEffects' => 'none', 'label' => 'Webhook',
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
                'name' => 'schedule_trigger', 'category' => 'trigger', 'sideEffects' => 'none', 'label' => 'Schedule',
                // ScheduleTriggerExecutor.php:23-28 array_merges its inputs into the
                // TOP level alongside these two. Composition is correct HERE because
                // the merge is genuinely top-level -- unlike `wait`, which nests.
                'emits' => 'input-map-merged',
                'outputShape' => [
                    ['path' => 'cron', 'type' => 'string', 'description' => 'The cron expression that fired.'],
                    ['path' => 'timezone', 'type' => 'string', 'description' => 'The timezone it was evaluated in.'],
                ],
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
                // Emits exactly the field keys its author defined -- no static list can
                // know them. UserInputExecutor returns $ctx->inputs['values'], which the
                // host fills from these declared fields.
                'outputShape' => static function (array $config): array {
                    $fields = $config['fields'] ?? [];

                    return array_values(array_map(
                        static fn (array $f): array => [
                            'path' => (string) ($f['key'] ?? ''),
                            'type' => 'unknown',
                            'description' => (string) ($f['label'] ?? ''),
                        ],
                        is_array($fields) ? $fields : [],
                    ));
                },
                'description' => 'Pause the flow until the user submits the configured form.', 'icon' => '✎',
                'pausesForHuman' => 'input',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'out', 'label' => 'values']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'title', 'label' => 'Form title', 'default' => 'Need your input'],
                    ['type' => 'json', 'key' => 'fields', 'label' => 'Fields (JSON)', 'language' => 'json', 'rows' => 6,
                        'default' => [['key' => 'answer', 'label' => 'Your answer', 'type' => 'textarea']]],
                    ['type' => 'switch', 'key' => 'autoAnswerFromInput', 'label' => 'Let an incoming value answer this', 'default' => false,
                        'description' => 'Off by default: this node pauses for a person even when something already put a value on its input. Turn it on for a step that is a form when a human is present and a pass-through when an upstream node already produced the answer.'],
                ],
            ],

            // ───────────── Logic ─────────────
            [
                'name' => 'branch', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'Branch',
                // Read from BranchExecutor.php -- Port::branch($port, $ctx->input('in', $ctx->inputs)).
                'emits' => 'input',
                'description' => 'Multi-way branch on a condition or value.', 'icon' => '◇',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'true', 'label' => 'true'], ['id' => 'false', 'label' => 'false']],
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'condition', 'label' => 'Condition', 'example' => '{{ $json.active }}', 'required' => true],
                ],
            ],
            [
                'name' => 'switch_case', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'Switch',
                // Read from SwitchCaseExecutor.php -- Port::only($port, $ctx->input('in', ...)).
                'emits' => 'input',
                'description' => 'Route to one of N labelled outputs based on a key.', 'icon' => '⤳',
                'inputs' => [['id' => 'in']],
                'outputs' => [['id' => 'case_a', 'label' => 'a'], ['id' => 'case_b', 'label' => 'b'], ['id' => 'default', 'label' => 'default']],
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Switch on', 'example' => '{{ $json.kind }}', 'required' => true],
                    ['type' => 'json', 'key' => 'cases', 'label' => 'Cases (JSON)', 'default' => ['a' => 'case_a', 'b' => 'case_b']],
                ],
            ],
            [
                'name' => 'for_each', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'For Each',
                // Read from ForEachExecutor.php:25.
                'outputShape' => [
                    ['path' => 'items', 'type' => 'array', 'description' => 'The list that was iterated.'],
                    ['path' => 'count', 'type' => 'number', 'description' => 'How many items it held.'],
                ],
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
                'name' => 'merge', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'Merge',
                // MergeExecutor.php: mode 'merge' array_merges every assoc input at
                // the TOP level; mode 'concat' builds a list instead, whose elements
                // are not addressable as fields. So the relation is config-dependent,
                // and `concat` declares NOTHING rather than an empty field list --
                // `[]` would claim "emits no fields" of a kind that emits a list,
                // which is false and would refuse every reference.
                'emits' => static fn (array $config): ?string => ($config['mode'] ?? 'merge') === 'concat'
                    ? null
                    : 'inputs-merged',
                'description' => 'Combine multiple inputs into one object or array.', 'icon' => '⊕',
                'inputs' => [['id' => 'a'], ['id' => 'b']], 'outputs' => [['id' => 'out']],
                'configSchema' => [
                    ['type' => 'select', 'key' => 'mode', 'label' => 'Mode', 'default' => 'merge',
                        'options' => [['value' => 'merge', 'label' => 'Object merge'], ['value' => 'concat', 'label' => 'Array concat']]],
                ],
            ],
            [
                'name' => 'wait', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'Wait',
                // Read from WaitExecutor.php:25-29.
                'outputShape' => [
                    ['path' => 'waited', 'type' => 'string', 'description' => 'Which wait mode ran.'],
                    ['path' => 'duration', 'type' => 'number', 'description' => 'How long it waited.'],
                    ['path' => 'input', 'type' => 'unknown', 'description' => 'The value that arrived, carried forward.'],
                ],
                'description' => 'Sleep or wait for an external event.', 'icon' => '⏸',
                'configSchema' => [
                    ['type' => 'select', 'key' => 'mode', 'label' => 'Mode', 'default' => 'duration',
                        'options' => [['value' => 'duration', 'label' => 'Duration'], ['value' => 'until', 'label' => 'Until timestamp'], ['value' => 'event', 'label' => 'External event']]],
                    ['type' => 'text', 'key' => 'duration', 'label' => 'Duration', 'placeholder' => '5s, 10m, 1h', 'description' => 'Used when mode = duration.'],
                ],
            ],
            [
                'name' => 'transform', 'category' => 'logic', 'sideEffects' => 'none', 'label' => 'Transform',
                // TransformExecutor.php has TWO returns: $ctx->input('in', ...) when
                // no expression is configured, else Expr::evaluate($expression, ...).
                // So the relation itself depends on config -- the Closure form the
                // reference consumer proposed, using machinery outputShape already had.
                'emits' => static fn (array $config): string => ($config['expression'] ?? '') === ''
                    ? 'input'
                    : 'expression:expression',
                'description' => 'Reshape data with an expression.', 'icon' => 'ƒ',
                'configSchema' => [
                    ['type' => 'expression', 'key' => 'expression', 'label' => 'Expression',
                        'example' => "{{ { id: \$json.id, name: \$json.first + ' ' + \$json.last } }}", 'required' => true],
                ],
            ],

            // ───────────── Data ─────────────
            [
                'name' => 'memory_store', 'category' => 'data', 'sideEffects' => 'idempotent', 'label' => 'Memory Store',
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
                'name' => 'data_store', 'category' => 'data', 'sideEffects' => 'idempotent', 'label' => 'Data Store',
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
                'name' => 'variable', 'category' => 'data', 'sideEffects' => 'idempotent', 'label' => 'Variable',
                // Read from VariableExecutor.php -- Expr::evaluate($ctx->option('value'), ...).
                'emits' => 'expression:value',
                'description' => 'Workflow-scoped value used by other nodes.', 'icon' => '𝓍',
                'configSchema' => [
                    ['type' => 'text', 'key' => 'name', 'label' => 'Name', 'required' => true],
                    ['type' => 'expression', 'key' => 'value', 'label' => 'Value', 'required' => true],
                ],
            ],

            // ───────────── AI ─────────────
            [
                'name' => 'llm_call', 'category' => 'ai', 'label' => 'LLM Call',
                // Config-dependent: `data` exists only when the author asked for a
                // schema (LlmCallExecutor.php:89). The rest is the client contract at
                // Nodes/Support/LlmClient.php:28 -- array{text:string,data?,usage?,raw?}.
                //
                // A Closure cannot be serialised, so a manifest-restored registry gets
                // `null` here rather than a list. That is the honest answer and it is a
                // real loss on the most-referenced kind there is; see the CHANGELOG.
                'outputShape' => static function (array $config): array {
                    $asked = ($config['response_schema'] ?? null) !== null
                        && $config['response_schema'] !== ''
                        && $config['response_schema'] !== [];

                    return array_values(array_filter([
                        ['path' => 'text', 'type' => 'string', 'description' => "The model's completion."],
                        $asked
                            ? ['path' => 'data', 'type' => 'unknown', 'description' => 'The parsed, schema-checked result.']
                            : null,
                        ['path' => 'usage', 'type' => 'object', 'description' => 'Token counts, when the provider reports them.'],
                        ['path' => 'raw', 'type' => 'unknown', 'description' => "The provider's untouched response."],
                    ]));
                },
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
                // Read from LlmRouterExecutor.php:169.
                'outputShape' => [
                    ['path' => 'route', 'type' => 'string', 'description' => 'The port the model chose.'],
                    ['path' => 'reason', 'type' => 'string', 'description' => 'Why the model chose it.'],
                    ['path' => 'input', 'type' => 'unknown', 'description' => 'The value that arrived, carried forward.'],
                ],
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
                'name' => 'embed_search', 'category' => 'ai', 'sideEffects' => 'none', 'label' => 'Embed & Search',
                // Read from EmbedSearchExecutor.php:26-29.
                'outputShape' => [
                    ['path' => 'query', 'type' => 'string', 'description' => 'The query that was embedded.'],
                    ['path' => 'matches', 'type' => 'array', 'description' => 'Vector-store hits for the query.'],
                ],
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
                // The HttpClient contract at Nodes/Support/HttpClient.php:16 --
                // array{status:int,headers:array<string,mixed>,body:mixed}. The
                // executor returns it unchanged (ApiRequestExecutor.php:31).
                'outputShape' => [
                    ['path' => 'status', 'type' => 'number', 'description' => 'HTTP status code.'],
                    ['path' => 'headers', 'type' => 'object', 'description' => 'Response headers.'],
                    ['path' => 'body', 'type' => 'unknown', 'description' => 'Parsed response body.'],
                ],
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
                'name' => 'webhook_out', 'category' => 'io', 'sideEffects' => 'unsafe-to-replay', 'label' => 'Send Webhook',
                // Read from WebhookOutExecutor.php:31.
                'outputShape' => [
                    ['path' => 'sent', 'type' => 'boolean', 'description' => 'True once the request was made.'],
                    ['path' => 'status', 'type' => 'number', 'description' => 'HTTP status, when the transport reported one.'],
                    ['path' => 'response', 'type' => 'unknown', 'description' => 'The response body, when there was one.'],
                ],
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
                // Read from HumanApprovalExecutor.php -- Port::branch(..., $ctx->input('in', ...)).
                'emits' => 'input',
                'description' => 'Pause until a human approves or denies.', 'icon' => '✓',
                'pausesForHuman' => 'approval',
                'inputs' => [['id' => 'in']], 'outputs' => [['id' => 'approved', 'label' => 'approved'], ['id' => 'denied', 'label' => 'denied']],
                'configSchema' => [
                    ['type' => 'text', 'key' => 'title', 'label' => 'Approval title', 'default' => 'Approve action'],
                    ['type' => 'textarea', 'key' => 'description', 'label' => 'Description for approver', 'rows' => 3],
                    ['type' => 'credential', 'key' => 'channel', 'label' => 'Notify channel', 'credentialType' => 'notify_channel'],
                    ['type' => 'switch', 'key' => 'autoAnswerFromInput', 'label' => 'Let an incoming value approve this', 'default' => false,
                        'description' => 'Off by default. Turning it on means the graph, not a person, can approve — an upstream value on the approved port decides and the gate never pauses. Weigh this harder than on a form.'],
                ],
            ],
            [
                'name' => 'notify', 'category' => 'human', 'sideEffects' => 'unsafe-to-replay', 'label' => 'Notify',
                // Read from NotifyExecutor.php:30.
                'outputShape' => [
                    ['path' => 'sent', 'type' => 'boolean', 'description' => 'True once the message was handed to the channel.'],
                    ['path' => 'channel', 'type' => 'string', 'description' => 'The channel it went to.'],
                    ['path' => 'to', 'type' => 'string', 'description' => 'The recipient.'],
                    ['path' => 'message', 'type' => 'string', 'description' => 'The rendered message.'],
                ],
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
                'name' => 'output', 'category' => 'output', 'sideEffects' => 'none', 'label' => 'Output',
                // Read from OutputExecutor.php -- returns $ctx->input('in', $ctx->inputs).
                'emits' => 'input',
                'description' => "Terminal node — captures the workflow's result.", 'icon' => '●',
                'inputs' => [['id' => 'in']], 'outputs' => [],
            ],
            [
                'name' => 'log', 'category' => 'output', 'sideEffects' => 'none', 'label' => 'Log',
                // Read from LogExecutor.php:25.
                'outputShape' => [
                    ['path' => 'logged', 'type' => 'string', 'description' => 'The message that was written.'],
                    ['path' => 'level', 'type' => 'string', 'description' => 'The level it was written at.'],
                ],
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
                'name' => 'note', 'category' => 'custom', 'sideEffects' => 'none', 'label' => 'Note',
                'description' => 'A canvas annotation. Never executed.', 'icon' => '🗒',
                'inputs' => [], 'outputs' => [],
            ],
            [
                'name' => 'subgraph', 'category' => 'custom', 'sideEffects' => 'none', 'label' => 'Subgraph',
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
                // Read from AgentExecutor.php:52 AND :64 -- it has TWO returns, and
                // the second (the max-steps path) adds `truncated`. Citing only the
                // first made a validator refuse {{ in.truncated }}: a real field, on
                // the path an author is most likely to be debugging.
                //
                // READ EVERY RETURN, not the top one. That is the method, and this
                // row is why it is written down.
                //
                // `truncated` appears on ONE path only -- the variants case arriving
                // on its own. Until a shape can express that it is declared flat:
                // over-permitting it on the normal path costs nothing, while omitting
                // it refuses a valid reference, and a false rejection is one the
                // author cannot comply with.
                'outputShape' => [
                    ['path' => 'text', 'type' => 'string', 'description' => 'The agent\'s final answer.'],
                    ['path' => 'steps', 'type' => 'array', 'description' => 'Each prompt/response round it took.'],
                    ['path' => 'truncated', 'type' => 'boolean', 'description' => 'Present when the agent stopped at its step limit.'],
                ],
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
