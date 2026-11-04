<?php

declare(strict_types=1);

namespace FancyFlow\Authoring;

use FancyFlow\Registry\KindId;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Registry\PortResolution;
use FancyFlow\Schema\PortDescriptor;

/**
 * Resolves the set of valid input / output port ids for a node, so
 * {@see FlowAuthoring::connect()} can reject an edge that references a port the
 * node does not have — a check {@see \FancyFlow\Workflow::import()} does NOT
 * perform (it validates edge endpoints exist, not that handles are real ports).
 *
 * The base set comes straight from the kind's declared ports:
 *   - `outputs`/`inputs` === null  → the engine default single `out` / `in` port
 *   - declared list                → those ids
 *   - declared empty `[]`          → an explicit terminal (no ports)
 *
 * A few kinds compute their ports from config at runtime (mirroring the TS
 * `outputs: (config) => …` closures). For those this resolver augments the base
 * set by reading config THE SAME WAY the executors document — never by calling
 * fancy-flow-php. It is deliberately generous: whole-graph correctness is still
 * owned by `validate_workflow` (which runs `Workflow::import`).
 */
final class PortResolver
{
    /**
     * @param array<string,mixed> $config
     * @return list<string>
     */
    /**
     * Every port a node of this kind could publish, given its config.
     *
     * DELEGATED to `FancyFlow\Registry\PortResolution` in the engine. This file
     * used to derive it here while the engine derived it from the kind's static
     * declaration -- so `describe_node_kind` correctly offered a third
     * `switch_case` port once three cases were configured, and the engine's
     * undelivered-edge warning reported that same port as impossible.
     *
     * The authoring API invited an edge and the runtime called it a mistake. Two
     * copies of one rule agree right up until someone edits one of them, and
     * nothing anywhere reports the divergence -- so there is one copy now, and it
     * lives with the runtime that has to honour it.
     *
     * @param  array<string,mixed> $config
     * @return list<string>
     */
    public static function outputs(?NodeKind $kind, array $config): array
    {
        // A TERMINAL kind declares an EMPTY port list, and nothing may connect
        // FROM it. That is an AUTHORING rule and it stays here, because the
        // engine legitimately answers differently: `activatedPorts` publishes
        // `out` for such a node, a historical fallback kept so that a chain
        // through one is not silently cut.
        //
        // Two different questions -- "what may I connect from?" and "what does
        // it publish at run time?" -- so unifying them was wrong. Only the
        // CONFIG-DERIVED derivation was duplicated, and only that is delegated.
        if ($kind !== null && $kind->outputs === []) {
            return [];
        }

        return PortResolution::possible(null, $kind, $config);
    }

    /**
     * @param array<string,mixed> $config
     * @return list<string>
     */
    public static function inputs(?NodeKind $kind, array $config): array
    {
        if ($kind === null) {
            return ['in'];
        }

        return self::declared($kind->inputs, 'in');
    }

    /**
     * @param list<PortDescriptor>|null $ports
     * @return list<string>
     */
    private static function declared(?array $ports, string $default): array
    {
        if ($ports === null) {
            return [$default];
        }

        return array_values(array_map(static fn (PortDescriptor $p): string => $p->id, $ports));
    }



}
