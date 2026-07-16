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
 *   3. Any other value → published on every declared output port.
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
}
