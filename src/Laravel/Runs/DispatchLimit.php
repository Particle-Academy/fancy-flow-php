<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Runs;

use FancyFlow\Laravel\Models\WorkflowRunNode;
use InvalidArgumentException;

/**
 * How many of ONE run's nodes may be held at once, and which ready nodes go next.
 *
 * ## Serial is the default
 *
 * A node is dispatched only when the node before it has settled: one node of a
 * run on the queue at a time, in the graph's own declaration order. Parallel
 * dispatch of a ready frontier is something a host ASKS for.
 *
 * It used to be the other way round -- an unset limit dispatched the whole
 * frontier -- and several nodes of one run sitting on the queue together is
 * exactly the condition the 0.53.1 sibling-order bug needed. "What ran, in what
 * order" also has to be the same answer on every run of the same graph, and a
 * frontier racing across workers cannot give it.
 *
 * ## The limit
 *
 * | value | meaning |
 * |---|---|
 * | unset / `null` | **1**: serial. A host whose published config still reads `env('FANCY_FLOW_MAX_CONCURRENT')` is serial too, which is the point of a default. |
 * | `N >= 1` | up to N held at once |
 * | `0` or `"unlimited"` | the whole ready frontier ({@see UNLIMITED}) |
 * | anything else | refused, by name |
 *
 * A negative number used to mean unlimited too. Under a serial default, a typo
 * that silently turned a run parallel is the one failure this must not have, so
 * it is refused instead.
 *
 * ## Held means claimed OR paused
 *
 * A node parked on a person keeps its slot. Otherwise a gate would open a gap
 * for a sibling to queue alongside it, while the person is still deciding. (On
 * this driver a pause parks the whole run anyway, so today that changes nothing
 * observable; the rule is stated here so it cannot come apart from the TS and
 * Python coordinators, where a pause does not park the run.)
 */
final class DispatchLimit
{
    /** Dispatch the whole ready frontier. Named so nobody writes a bare 0. */
    public const UNLIMITED = 0;

    /** One node of a run held at a time: the default. */
    public const SERIAL = 1;

    /**
     * The effective limit for a run: its own value when it has one, else config.
     *
     * @return int|null null is unlimited
     *
     * @throws InvalidArgumentException for a value that is neither a limit nor unlimited
     */
    public static function resolve(mixed $runValue, mixed $configValue): ?int
    {
        return $runValue !== null
            ? self::parse($runValue, 'the run\'s maxConcurrent')
            : self::parse($configValue ?? self::SERIAL, 'fancy-flow.queue.max_concurrent');
    }

    /**
     * Refuse a per-run limit at the moment it is set, not when the first advance
     * trips over it on a worker.
     */
    public static function assertValid(?int $maxConcurrent): void
    {
        if ($maxConcurrent !== null) {
            self::parse($maxConcurrent, 'maxConcurrent');
        }
    }

    /**
     * The ready nodes that may be dispatched now, in the order given.
     *
     * Measured against work ALREADY HELD, not the size of this batch: two nodes
     * settling at once each trigger an advance, and a per-batch cap would let
     * each dispatch its own quota.
     *
     * @param  list<string>  $ready  from {@see Frontier::compute()}, in declaration order
     * @param  array<string,array{status:string,ports:list<string>}>  $state
     * @param  int|null  $limit  from {@see resolve()}; null is unlimited
     * @return list<string>
     */
    public static function select(array $ready, array $state, ?int $limit): array
    {
        if ($limit === null) {
            return array_values($ready);
        }

        $held = 0;
        foreach ($state as $entry) {
            if ($entry['status'] === WorkflowRunNode::CLAIMED || $entry['status'] === WorkflowRunNode::PAUSED) {
                $held++;
            }
        }

        return array_slice(array_values($ready), 0, max(0, $limit - $held));
    }

    private static function parse(mixed $value, string $what): ?int
    {
        if ($value === 'unlimited') {
            return null;
        }

        if ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^\d+$/', trim($value)) === 1)) {
            $limit = (int) $value;

            return $limit === self::UNLIMITED ? null : $limit;
        }

        throw new InvalidArgumentException(sprintf(
            '%s must be a positive integer, 0 or "unlimited" for the whole frontier, or unset for serial; got %s.',
            $what,
            var_export($value, true),
        ));
    }
}
