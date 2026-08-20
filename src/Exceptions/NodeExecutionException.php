<?php

declare(strict_types=1);

namespace FancyFlow\Exceptions;

use Throwable;

/**
 * A node's executor threw. Says WHICH node, and keeps what the original said.
 *
 * ## Why this exists
 *
 * A node-level failure used to be recorded as `$e->getMessage()` and nothing
 * else. The emitted RunEvent carried `$node->id`, so a host watching events
 * could tell — but anyone reading `RunResult->error`, or catching on the durable
 * path, got the message alone.
 *
 * That is worst for the messages that are otherwise good. A consumer hit the
 * StructuredOutput truncation error on an Op with several `llm_call` nodes: the
 * message correctly told them to raise `max_tokens` or narrow the schema, and
 * did not say which node's. They bisected the Op to find out.
 *
 * ## Why it decorates here rather than at the throw site
 *
 * Threading a node id into every executor that can fail would cover the failure
 * that prompted it and miss the next one. Wrapping at the runner means EVERY
 * node-level failure gains attribution, including from executors that know
 * nothing about this class — which is the consumer's own preferred shape, and
 * the right one.
 *
 * ## What it deliberately does not do
 *
 * It does not retry, coerce, or soften anything. A truncated structured response
 * decodes to nothing and is indistinguishable from a model that legitimately
 * found no results, so a retry would silently process zero records. Failing is
 * correct; the only defect was not saying where.
 *
 * The original throwable is the `previous`, never replaced — the part of the
 * message that says what to DO lives there.
 */
final class NodeExecutionException extends FlowException
{
    public function __construct(
        public readonly string $nodeId,
        public readonly ?string $nodeKind,
        public readonly ?string $nodeLabel,
        Throwable $previous,
    ) {
        parent::__construct(
            self::describe($nodeId, $nodeKind, $nodeLabel, $previous->getMessage()),
            0,
            $previous,
        );
    }

    /** Wrap a throwable with the node that was executing when it was raised. */
    public static function at(string $nodeId, ?string $nodeKind, ?string $nodeLabel, Throwable $e): self
    {
        // Already attributed -- a nested run should not double-wrap and report
        // the outer node for an inner node's failure.
        if ($e instanceof self) {
            return $e;
        }

        return new self($nodeId, $nodeKind, $nodeLabel, $e);
    }

    /**
     * `node "Draft the summary" (n-42, llm_call): <original>`
     *
     * The author's own label leads, because that is the name they gave it and
     * the one they will recognise in their graph. The id follows for anyone
     * matching against events or logs.
     */
    private static function describe(string $id, ?string $kind, ?string $label, string $message): string
    {
        $subject = $label !== null && $label !== ''
            ? sprintf('node "%s" (%s', $label, $id)
            : sprintf('node (%s', $id);

        $subject .= $kind !== null && $kind !== '' ? ", {$kind})" : ')';

        return "{$subject}: {$message}";
    }
}
