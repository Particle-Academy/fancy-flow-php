<?php

declare(strict_types=1);

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Nodes\Ai\EmbedSearchExecutor;
use FancyFlow\Nodes\Ai\LlmCallExecutor;
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
use FancyFlow\Nodes\Support\ArrayStore;
use FancyFlow\Nodes\Support\EchoHttpClient;
use FancyFlow\Nodes\Support\EchoLlmClient;
use FancyFlow\Nodes\Support\EchoToolInvoker;
use FancyFlow\Nodes\Support\EmptyVectorStore;
use FancyFlow\Nodes\Support\RecordingNotifier;
use FancyFlow\Nodes\Trigger\ManualTriggerExecutor;
use FancyFlow\Nodes\Trigger\ScheduleTriggerExecutor;
use FancyFlow\Nodes\Trigger\WebhookTriggerExecutor;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;
use FancyFlow\Schema\FlowNode;

/** Run an executor directly with a config + inputs, capturing emitted events. */
function ffExec(NodeExecutor $exec, array $config = [], array $inputs = [], ?array &$events = null): mixed
{
    $captured = [];
    $ctx = new ExecutionContext(
        new FlowNode(id: 'n', type: 't', config: $config),
        $inputs,
        function (RunEvent $e) use (&$captured) { $captured[] = $e; },
    );
    $result = $exec->execute($ctx);
    $events = $captured;

    return $result;
}

it('manual/webhook/schedule triggers pass their payload through', function () {
    expect(ffExec(new ManualTriggerExecutor(), inputs: ['a' => 1]))->toBe(['a' => 1]);
    expect(ffExec(new WebhookTriggerExecutor(), inputs: ['payload' => ['b' => 2]]))->toBe(['b' => 2]);
    expect(ffExec(new ScheduleTriggerExecutor(), ['cron' => '* * * * *', 'timezone' => 'UTC']))
        ->toMatchArray(['cron' => '* * * * *', 'timezone' => 'UTC']);
});

it('user_input surfaces submitted values', function () {
    expect(ffExec(new UserInputExecutor(), inputs: ['values' => ['answer' => 'yes']]))->toBe(['answer' => 'yes']);
});

it('human_approval routes approved / denied', function () {
    expect(ffExec(new HumanApprovalExecutor(), inputs: ['approved' => true, 'in' => 'X']))
        ->toBe(['branch' => 'approved', 'value' => 'X']);
    expect(ffExec(new HumanApprovalExecutor(), inputs: ['approved' => false, 'in' => 'X']))
        ->toBe(['branch' => 'denied', 'value' => 'X']);
});

it('notify records the message via the notifier', function () {
    $notifier = new RecordingNotifier();
    $out = ffExec(new NotifyExecutor($notifier), ['channel' => 'slack', 'to' => '#x', 'message' => 'Hi {{ name }}'], ['name' => 'Ada']);

    expect($out)->toMatchArray(['sent' => true, 'channel' => 'slack', 'to' => '#x', 'message' => 'Hi Ada']);
    expect($notifier->sent)->toBe([['channel' => 'slack', 'to' => '#x', 'message' => 'Hi Ada']]);
});

it('branch evaluates a condition to the right port', function () {
    expect(ffExec(new BranchExecutor(), ['condition' => '{{ $json.ok }}'], ['in' => ['ok' => true]]))
        ->toBe(['branch' => 'true', 'value' => ['ok' => true]]);
    expect(ffExec(new BranchExecutor(), ['condition' => '{{ $json.ok }}'], ['in' => ['ok' => false]]))
        ->toBe(['branch' => 'false', 'value' => ['ok' => false]]);
});

it('switch_case maps a value to a port', function () {
    $config = ['value' => '{{ $json.kind }}', 'cases' => ['a' => 'case_a', 'b' => 'case_b']];
    expect(ffExec(new SwitchCaseExecutor(), $config, ['in' => ['kind' => 'b']]))
        ->toBe(['__port' => 'case_b', 'value' => ['kind' => 'b']]);
    expect(ffExec(new SwitchCaseExecutor(), $config, ['in' => ['kind' => 'z']]))
        ->toBe(['__port' => 'default', 'value' => ['kind' => 'z']]);
});

it('for_each resolves a list', function () {
    expect(ffExec(new ForEachExecutor(), ['source' => '{{ $json.users }}'], ['in' => ['users' => ['x', 'y']]]))
        ->toBe(['items' => ['x', 'y'], 'count' => 2]);
});

it('merge combines inputs in object and concat modes', function () {
    expect(ffExec(new MergeExecutor(), ['mode' => 'merge'], ['a' => ['x' => 1], 'b' => ['y' => 2]]))
        ->toBe(['x' => 1, 'y' => 2]);
    expect(ffExec(new MergeExecutor(), ['mode' => 'concat'], ['a' => [1, 2], 'b' => [3]]))
        ->toBe([1, 2, 3]);
    // dead branch (null) is ignored
    expect(ffExec(new MergeExecutor(), ['mode' => 'merge'], ['a' => ['x' => 1], 'b' => null]))
        ->toBe(['x' => 1]);
});

it('wait does not sleep but records the request', function () {
    $out = ffExec(new WaitExecutor(), ['mode' => 'duration', 'duration' => '5s'], ['in' => 'payload'], $events);
    expect($out)->toMatchArray(['waited' => 'duration', 'duration' => '5s', 'input' => 'payload']);
    expect($events[0]->type)->toBe('log');
});

it('transform reshapes via an expression', function () {
    expect(ffExec(new TransformExecutor(), ['expression' => '{{ $json.name }}'], ['in' => ['name' => 'Bo']]))->toBe('Bo');
    expect(ffExec(new TransformExecutor(), [], ['in' => 'passthrough']))->toBe('passthrough');
});

it('memory_store reads, writes, and appends', function () {
    $store = new ArrayStore();
    ffExec(new MemoryStoreExecutor($store), ['operation' => 'write', 'key' => 'k', 'value' => '{{ $json }}'], ['in' => 'V']);
    expect($store->get('k'))->toBe('V');
    expect(ffExec(new MemoryStoreExecutor($store), ['operation' => 'read', 'key' => 'k']))->toBe('V');

    $store2 = new ArrayStore();
    ffExec(new MemoryStoreExecutor($store2), ['operation' => 'append', 'key' => 'log', 'value' => '{{ $json }}'], ['in' => 'a']);
    $out = ffExec(new MemoryStoreExecutor($store2), ['operation' => 'append', 'key' => 'log', 'value' => '{{ $json }}'], ['in' => 'b']);
    expect($out)->toBe(['a', 'b']);
});

it('data_store gets, sets, lists, and queries', function () {
    $store = new ArrayStore();
    ffExec(new DataStoreExecutor($store), ['operation' => 'set', 'table' => 'users', 'key' => '1', 'value' => '{{ $json }}'], ['in' => ['name' => 'A', 'role' => 'admin']]);
    ffExec(new DataStoreExecutor($store), ['operation' => 'set', 'table' => 'users', 'key' => '2', 'value' => '{{ $json }}'], ['in' => ['name' => 'B', 'role' => 'user']]);

    expect(ffExec(new DataStoreExecutor($store), ['operation' => 'get', 'table' => 'users', 'key' => '1']))->toBe(['name' => 'A', 'role' => 'admin']);
    expect(ffExec(new DataStoreExecutor($store), ['operation' => 'list', 'table' => 'users']))->toHaveCount(2);
    expect(ffExec(new DataStoreExecutor($store), ['operation' => 'query', 'table' => 'users', 'where' => ['role' => 'admin']]))
        ->toBe([['name' => 'A', 'role' => 'admin']]);
    expect(ffExec(new DataStoreExecutor($store), ['operation' => 'delete', 'table' => 'users', 'key' => '1']))->toBe(['deleted' => '1']);
});

it('variable resolves its value', function () {
    expect(ffExec(new VariableExecutor(), ['name' => 'greeting', 'value' => 'Hello {{ $json.who }}'], ['in' => ['who' => 'world']]))
        ->toBe('Hello world');
});

it('llm_call returns a completion from the client', function () {
    $client = new EchoLlmClient();
    $out = ffExec(new LlmCallExecutor($client), ['model' => 'claude', 'prompt' => '{{ $json.q }}'], ['in' => ['q' => 'ping']]);
    expect($out['text'])->toBe('[claude] ping');
    expect($client->prompts)->toHaveCount(1);
});

it('tool_use invokes a tool', function () {
    $invoker = new EchoToolInvoker();
    $out = ffExec(new ToolUseExecutor($invoker), ['tool' => 'search', 'args' => '{{ $json }}'], ['in' => ['q' => 'x']]);
    expect($out)->toBe(['tool' => 'search', 'args' => ['q' => 'x']]);
});

it('embed_search queries the vector store', function () {
    $out = ffExec(new EmbedSearchExecutor(new EmptyVectorStore()), ['query' => '{{ $json.q }}', 'topK' => 3], ['in' => ['q' => 'hi']]);
    expect($out)->toBe(['query' => 'hi', 'matches' => []]);
});

it('api_request sends via the http client', function () {
    $http = new EchoHttpClient();
    $out = ffExec(new ApiRequestExecutor($http), ['method' => 'post', 'url' => 'https://x/{{ $json.id }}', 'body' => '{{ $json }}'], ['in' => ['id' => 7]]);
    expect($out['status'])->toBe(200);
    expect($http->requests[0]['method'])->toBe('POST');
    expect($http->requests[0]['url'])->toBe('https://x/7');
});

it('webhook_out posts a payload', function () {
    $http = new EchoHttpClient();
    $out = ffExec(new WebhookOutExecutor($http), ['url' => 'https://hook', 'payload' => '{{ $json }}'], ['in' => ['a' => 1]]);
    expect($out)->toMatchArray(['sent' => true, 'status' => 200]);
    expect($http->requests[0]['body'])->toBe(['a' => 1]);
});

it('output captures its input', function () {
    expect(ffExec(new OutputExecutor(), inputs: ['in' => ['result' => 42]]))->toBe(['result' => 42]);
});

it('log emits an event at the configured level', function () {
    $out = ffExec(new LogExecutor(), ['level' => 'warn', 'message' => 'careful {{ $json.n }}'], ['in' => ['n' => 3]], $events);
    expect($out)->toBe(['logged' => 'careful 3', 'level' => 'warn']);
    expect($events[0]->type)->toBe('log');
    expect($events[0]->level)->toBe('warn');
});
