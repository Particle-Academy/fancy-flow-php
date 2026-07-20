<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * The human-pause contract — the PHP twin of fancy-flow's `pause` module.
 *
 * A workflow waiting for a person is not an error, but it travels the same
 * channel as one: the executor aborts, the runner records a reason string, and
 * something downstream decides whether that string meant "failed" or "waiting".
 *
 * That seam existed before this class, as two `str_starts_with` checks in
 * {@see \FancyFlow\Laravel\Jobs\RunWorkflowJob} against constants owned by two
 * BUILTIN executors. It worked, and it was invisible: a third-party
 * human-input node had no way to announce that it pauses, and nothing stopped
 * a refactor from removing the mechanism out from under published packages.
 *
 * The wire format is byte-identical to the TS side on purpose. The same string
 * is produced by a node running on either runtime and decoded by a runner on
 * either runtime — which is what lets a consumer author in TS and execute in
 * PHP without the pause semantics quietly diverging.
 *
 * @see decode() — the one method a durable runner needs.
 */
final class Pause
{
    /** Marks a reason string as a pause rather than a failure. */
    public const PREFIX = 'fancy-flow:pause:';

    /**
     * Reason prefixes shipped before this contract, kept decodable forever.
     *
     * These are sitting in the `error` column of every run that paused under an
     * older version. A resume path that only works for new runs is not a resume
     * path — it strands everything already in flight.
     *
     * @var array<string,string> prefix => awaiting
     */
    public const LEGACY_PREFIXES = [
        'awaiting-approval:' => 'approval',
        'awaiting-input:' => 'input',
    ];

    /**
     * Encode a pause as the reason string an executor aborts with.
     *
     * The payload is JSON rather than delimited fields because a node id may
     * contain a colon, and a positional encoding that breaks on user data is
     * the kind of bug that only ever shows up in someone else's graph.
     *
     * One deliberate asymmetry with the TS twin: TS distinguishes an absent
     * detail from an explicitly null one and preserves both, where PHP has only
     * `null`. So a null detail is omitted here. It round-trips in both
     * directions — a TS-encoded `"detail":null` decodes to null in PHP — the
     * only thing PHP cannot do is re-emit that null as distinct from absent.
     */
    public static function encode(PauseSignal $signal): string
    {
        $payload = ['nodeId' => $signal->nodeId, 'awaiting' => $signal->awaiting];

        if ($signal->detail !== null) {
            $payload['detail'] = $signal->detail;
        }

        return self::PREFIX.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Decode a run's error reason into a pause, or null if it was a real
     * failure.
     *
     * This is the whole contract from a runner's side: call it on
     * `$result->error`, and if it returns non-null, persist the run as waiting
     * on `$signal->nodeId` instead of failing it.
     */
    public static function decode(?string $reason): ?PauseSignal
    {
        if ($reason === null) {
            return null;
        }

        if (str_starts_with($reason, self::PREFIX)) {
            $body = substr($reason, strlen(self::PREFIX));
            $parsed = json_decode($body, true);

            // A malformed payload is a corrupt pause, not something to invent a
            // node id for.
            if (! is_array($parsed)
                || ! isset($parsed['nodeId'], $parsed['awaiting'])
                || ! is_string($parsed['nodeId'])
                || ! is_string($parsed['awaiting'])) {
                return null;
            }

            return new PauseSignal(
                $parsed['nodeId'],
                $parsed['awaiting'],
                $parsed['detail'] ?? null,
            );
        }

        foreach (self::LEGACY_PREFIXES as $prefix => $awaiting) {
            if (str_starts_with($reason, $prefix)) {
                return new PauseSignal(substr($reason, strlen($prefix)), $awaiting);
            }
        }

        return null;
    }

    /** True when a run's error reason is actually a pause. */
    public static function is(?string $reason): bool
    {
        return self::decode($reason) !== null;
    }
}
