<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Who is running, which step this is, and how many times it has been tried.
 *
 * ## Why an engine needs this at all
 *
 * A node that WRITES to somebody else's system — charge a card, send a message,
 * open a pull request — can only survive a retry if the retry carries the same
 * idempotency key the first attempt did. Otherwise the provider treats the
 * second call as a new request and the customer is charged twice.
 *
 * Until this existed {@see ExecutionContext} was `{node, inputs, emit, depth}`,
 * and nothing in it could produce such a key. Both obvious fallbacks are worse
 * than sending no key at all:
 *
 *   - the **node id alone** is stable across retries, and also across RUNS —
 *     two legitimate payments share a key and the provider silently collapses
 *     the second into the first: a payment that never happened, reported as
 *     success;
 *   - a **fresh random value** is unique per run, and also per ATTEMPT — a
 *     retry creates a second charge, which is the thing being avoided.
 *
 * ## What actually identifies a step
 *
 * Not `(run, node)`. A node legitimately executes more than once inside one
 * run: once per subflow invocation, once per iteration of a loop an executor
 * drives itself. `(run, node)` would give every one of those the same key, and
 * a provider would honour exactly one of them.
 *
 * So a step is identified by the **path of invocations that led to it**, plus
 * an optional **occurrence** for repetition at the same level:
 *
 *     runKey ":" segment ("/" segment)*     segment := escape(id) ["#" occurrence]
 *
 * And the part that is easy to get backwards: **`attempt` is NOT in the key.**
 * It is carried here for logging and for {@see isReplaySafe()}, and putting it
 * in the key would restore the exact bug the key exists to prevent.
 *
 * Pinned cross-runtime by `shared/flow-run-identity` in
 * `particle-academy/fancy-conformance`.
 */
final class RunIdentity implements JsonSerializable
{
    /** @var list<string> */
    public readonly array $path;

    public readonly int $attempt;

    /** ISO-8601 UTC instant of attempt 1 of this step. */
    public readonly string $firstAttemptAt;

    /**
     * @param string       $runKey         Stable for the whole run: same across retries, resumes, workers and hosts.
     * @param list<string> $path           Enclosing invocation segments, outermost first, ALREADY RENDERED.
     *                                     Empty at the top level. A subflow pushes the invoking node's id.
     * @param int          $attempt        1-based attempt of THIS logical step. Never part of the key. The durable
     *                                     driver sets it from the node's claim row, which is exact; a plain
     *                                     FlowRunner call gets whatever the host passed, which is run-scoped
     *                                     and therefore conservative.
     * @param string       $firstAttemptAt ISO-8601 UTC instant of attempt 1 of this step.
     */
    public function __construct(
        public readonly string $runKey,
        array $path = [],
        int $attempt = 1,
        string $firstAttemptAt = '',
    ) {
        if (trim($runKey) === '') {
            throw new InvalidArgumentException('RunIdentity: runKey must be a non-empty string.');
        }

        $this->path = array_values(array_map(strval(...), $path));
        $this->attempt = max(1, $attempt);
        // An empty string means "now". Callers with a claim row should pass the
        // row's own first-claim time — that is what makes the window check
        // exact rather than conservative.
        $this->firstAttemptAt = $firstAttemptAt !== ''
            ? $firstAttemptAt
            : (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * Escape one segment so the composition is injective.
     *
     * `%` FIRST, or the escaping is not reversible: escaping `/` before `%`
     * turns a literal `a%2Fb` into the same text as the escaped form of `a/b`,
     * which is the collision this exists to prevent, reintroduced by its own
     * fix.
     */
    public static function escapeSegment(string $value): string
    {
        return str_replace(['%', '/', '#'], ['%25', '%2F', '%23'], $value);
    }

    /**
     * The identity of one execution of one node — stable across retries of that
     * execution, distinct from every other execution of the same node.
     *
     * Pass `$occurrence` when an executor runs the same node more than once at
     * the same level (a loop body, one item of a fan-out it drives itself).
     */
    public function stepKey(string $nodeId, ?int $occurrence = null): string
    {
        $segments = $this->path;
        $segments[] = self::render($nodeId, $occurrence);

        return $this->runKey.':'.implode('/', $segments);
    }

    /**
     * A child identity for work nested inside this step.
     *
     * `subflow` pushes the invoking node's id, so a node inside the child graph
     * cannot collide with a same-named node in the parent. Attempt and
     * `firstAttemptAt` are carried down unchanged: the nested work happens
     * inside this step's attempt, and shares its clock.
     */
    public function descend(string $segment, ?int $occurrence = null): self
    {
        $path = $this->path;
        $path[] = self::render($segment, $occurrence);

        return new self($this->runKey, $path, $this->attempt, $this->firstAttemptAt);
    }

    /** A copy on a different attempt, first-attempt clock preserved unless replaced. */
    public function withAttempt(int $attempt, ?string $firstAttemptAt = null): self
    {
        return new self($this->runKey, $this->path, $attempt, $firstAttemptAt ?? $this->firstAttemptAt);
    }

    /**
     * May this attempt reuse the step key and still be deduplicated?
     *
     * Providers forget idempotency keys — Stripe after 24 hours. Past that
     * window, resending the key creates a second charge and sending a fresh one
     * creates a second charge, so **the caller must refuse rather than pick
     * between them**: a loud stuck run beats a silent double write.
     *
     * `true` on attempt 1 whatever the elapsed time — nothing was sent on an
     * earlier attempt, so there is nothing for the provider to have forgotten.
     * That is what lets a run park on a human gate for a week and then write.
     *
     * `$windowSeconds = null` means the provider does not expire keys. `0`
     * means it does not dedupe at all, so no retry may reuse a key — it is a
     * real window, not an absent one, and reading `0` as `null` turns "this
     * provider does not dedupe" into "this provider dedupes forever".
     */
    public function isReplaySafe(?int $windowSeconds, DateTimeInterface|string|null $now = null): bool
    {
        if ($this->attempt <= 1) {
            return true;
        }
        if ($windowSeconds === null) {
            return true;
        }
        if ($windowSeconds <= 0) {
            return false;
        }

        $nowTs = self::instant($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')));
        // Clock skew between two workers must not turn a legitimate retry into
        // a refusal, so a negative elapsed clamps to zero.
        $elapsed = max(0.0, $nowTs - self::instant($this->firstAttemptAt));

        // Inclusive: a key written at T is remembered THROUGH T + window.
        return $elapsed <= $windowSeconds;
    }

    /** @return array{runKey:string,path:list<string>,attempt:int,firstAttemptAt:string} */
    public function toArray(): array
    {
        return [
            'runKey' => $this->runKey,
            'path' => $this->path,
            'attempt' => $this->attempt,
            'firstAttemptAt' => $this->firstAttemptAt,
        ];
    }

    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /** Rebuild from a queue payload, or promote a bare run key. */
    public static function from(self|array|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }
        if (is_string($value)) {
            return new self($value);
        }

        return new self(
            (string) ($value['runKey'] ?? ''),
            $value['path'] ?? [],
            (int) ($value['attempt'] ?? 1),
            (string) ($value['firstAttemptAt'] ?? ''),
        );
    }

    private static function render(string $value, ?int $occurrence): string
    {
        $escaped = self::escapeSegment($value);

        // `$occurrence === 0` is a real occurrence. A truthiness check here
        // silently collapses iteration 0 into the un-iterated key.
        return $occurrence === null ? $escaped : $escaped.'#'.$occurrence;
    }

    private static function instant(DateTimeInterface|string $value): float
    {
        if ($value instanceof DateTimeInterface) {
            return (float) $value->format('U.u');
        }

        $parsed = date_create_immutable($value);
        if ($parsed === false) {
            throw new InvalidArgumentException(
                'RunIdentity: firstAttemptAt is not a parseable timestamp: '.json_encode($value)
            );
        }

        return (float) $parsed->format('U.u');
    }
}
