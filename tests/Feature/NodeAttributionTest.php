<?php

declare(strict_types=1);

/**
 * A node-level failure must say WHICH node failed.
 *
 * Reported by a consumer running an Op with several `llm_call` nodes. It failed
 * with the StructuredOutput truncation error, which is a genuinely good message
 * — it names truncation as the likely cause and tells you to raise `max_tokens`
 * or narrow the schema. What it never said is which node's `max_tokens` to
 * raise, so the author bisected a composed Op to find out.
 *
 * The runner already passes `$node->id` to the emitted RunEvent, so a host
 * watching events could tell. Anyone reading `RunResult->error`, or catching the
 * throwable on the durable path, could not: the recorded string was
 * `$e->getMessage()` and nothing else.
 *
 * The fix decorates at the RUNNER rather than in `StructuredOutput`, which is
 * the consumer's own preference and the right one: every node-level failure
 * gains the context, not just the one that prompted the report.
 *
 * Note what is NOT being changed. They were explicit that they are not asking
 * for a retry, and they are right — a truncated array decodes to nothing and is
 * indistinguishable from a model that legitimately found no results, so
 * retrying or coercing would silently process zero records. Failing is correct.
 * The only complaint was not being told where.
 */

use FancyFlow\Engine\FlowRunner;
use FancyFlow\Exceptions\NodeExecutionException;
use FancyFlow\ExecutorRegistry;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;

/** A graph of one node that always throws, so the failure path is the only path. */
function explodingGraph(?string $label = null): FlowGraph
{
    return new FlowGraph(
        nodes: [new FlowNode(id: 'n-42', type: 'llm_call', label: $label)],
        edges: [],
    );
}

function explodingRegistry(string $message): ExecutorRegistry
{
    return (new ExecutorRegistry())->bind('llm_call', static function () use ($message): mixed {
        throw new RuntimeException($message);
    });
}

it('names the failing node in the recorded error', function () {
    $result = (new FlowRunner())->run(explodingGraph(), explodingRegistry('truncated'));

    expect($result->ok)->toBeFalse()
        ->and($result->error)->toContain('n-42');
});

it('keeps the original message, because that is the part that says what to do', function () {
    // The truncation message tells the author to raise max_tokens. Losing it
    // while adding the node id would trade one missing half for the other.
    $result = (new FlowRunner())->run(explodingGraph(), explodingRegistry('StructuredOutput truncated — raise max_tokens'));

    expect($result->error)->toContain('raise max_tokens');
});

it('uses the label when the node has one, since that is what the author named it', function () {
    $result = (new FlowRunner())->run(explodingGraph(label: 'Draft the summary'), explodingRegistry('boom'));

    expect($result->error)->toContain('Draft the summary');
});

it('carries the node on the exception, not only in the string', function () {
    // A host that catches on the durable path should not have to parse a message
    // to find out which node it was.
    $thrown = null;

    try {
        throw new NodeExecutionException(
            nodeId: 'n-42',
            nodeKind: 'llm_call',
            nodeLabel: 'Draft the summary',
            previous: new RuntimeException('truncated'),
        );
    } catch (NodeExecutionException $e) {
        $thrown = $e;
    }

    expect($thrown->nodeId)->toBe('n-42')
        ->and($thrown->nodeKind)->toBe('llm_call')
        ->and($thrown->nodeLabel)->toBe('Draft the summary')
        ->and($thrown->getPrevious()?->getMessage())->toBe('truncated');
});

/**
 * The trap this change fell into on its first attempt, pinned so it cannot be
 * remade.
 *
 * `abort()` carries its reason VERBATIM, and `pauseForHuman()` aborts with a
 * `Pause::encode()` payload that the durable layer decodes straight back out of
 * the message. Decorating that message does not merely read oddly — it stops the
 * payload being decodable, so a run that should be parked waiting on a person is
 * recorded as an unrecognised error and is simply dead.
 *
 * Wrapping every Throwable did exactly that: 72 tests went red, and the ones that
 * mattered were not the string comparisons but "it asserts a pause".
 */
it('never decorates an abort, because the reason is carried verbatim', function () {
    $executors = (new ExecutorRegistry())->bind(
        'llm_call',
        static fn (\FancyFlow\Runtime\ExecutionContext $ctx) => $ctx->abort('nope'),
    );

    $result = (new FlowRunner())->run(explodingGraph(), $executors);

    expect($result->error)->toBe('nope');
});

it('never decorates a pause, or the run stops being resumable', function () {
    $executors = (new ExecutorRegistry())->bind(
        'llm_call',
        static fn (\FancyFlow\Runtime\ExecutionContext $ctx) => $ctx->pauseForHuman('input', ['fields' => ['email']]),
    );

    $result = (new FlowRunner())->run(explodingGraph(), $executors);

    // The assertion that matters is not the string but that it still DECODES.
    $pause = \FancyFlow\Runtime\Pause::decode((string) $result->error);

    expect($pause)->not->toBeNull()
        ->and($pause->nodeId)->toBe('n-42')
        ->and($pause->awaiting)->toBe('input');
});
