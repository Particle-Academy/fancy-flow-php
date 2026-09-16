<?php

declare(strict_types=1);

namespace FancyFlow\Runtime;

/**
 * Branching sugar for executor return values. The engine inspects an
 * executor's result and decides which output ports fire:
 *
 *   1. `Port::only('true', $value)`   → `['__port' => 'true', 'value' => …]`
 *      Only the named port emits, carrying `$value`.
 *   2. `Port::branch('true', $value)` → `['branch' => 'true', 'value' => …]`
 *      Decision sugar — only the named port emits. If `$value` is omitted the
 *      whole result object is carried (matches the TS `r.value ?? r` rule).
 *   3. `Port::many(['a', 'c'], $value)`  → `['__ports' => ['a','c'], 'value' => …]`
 *      Exactly those ports emit, each carrying `$value`.
 *      `Port::many(['a' => $x, 'c' => $y])` → `['__ports' => ['a' => $x, …]]`
 *      Exactly those ports emit, each carrying its OWN payload.
 *   4. Any other value → published on every declared output port.
 *
 * These mirror fancy-flow's `__port` / `branch` conventions exactly so an
 * identical graph branches identically on Node and PHP.
 */
final class Port
{
    /** @return array{__port:string,value:mixed} */
    public static function only(string $portId, mixed $value = null): array
    {
        return ['__port' => $portId, 'value' => $value];
    }

    /** @return array{branch:string,value:mixed} */
    public static function branch(string $portId, mixed $value = null): array
    {
        return ['branch' => $portId, 'value' => $value];
    }

    /**
     * A CHOSEN SUBSET of the ports (#18). Two shapes, one rule:
     *
     *   Port::many(['a', 'c'], $v)            both ports carry `$v`
     *   Port::many(['a' => $x, 'c' => $y])    each port carries its own
     *
     * A LIST is port ids; a MAP is port id => payload. An empty array lights
     * nothing, deliberately — the same answer an explicitly empty `outputs`
     * gives, and the honest one for a router that matched no rule. `$value` is
     * ignored for the map form, where each port already has its own.
     *
     * Before this a node could light one port or all of them, so a router that
     * matched two of five had to drop work or wake lanes nobody asked for.
     *
     * @param  list<string>|array<string,mixed>  $ports
     * @return array{__ports:list<string>|array<string,mixed>,value?:mixed}
     */
    public static function many(array $ports, mixed $value = null): array
    {
        return array_is_list($ports)
            ? ['__ports' => $ports, 'value' => $value]
            : ['__ports' => $ports];
    }
}
