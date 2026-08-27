<?php

declare(strict_types=1);

namespace FancyFlow\Registry;

use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;

/**
 * Which ports a node CAN publish — the one answer, for everybody.
 *
 * ## Why this exists
 *
 * Three kinds decide their ports from their own CONFIG rather than from a fixed
 * declaration: `switch_case` publishes one port per entry in its `cases` map,
 * `llm_router` one per declared route, and `subflow` gains `stream` in the
 * streaming modes. Their `NodeKind` can only carry a representative default.
 *
 * That answer was being computed in TWO places that did not agree:
 *
 *   - `fancy-flow-mcp`'s `PortResolver` derived them from config, so
 *     `describe_node_kind` correctly told an authoring agent that a third case
 *     existed once three cases were configured;
 *   - the ENGINE read only the kind's static declaration, so the
 *     undelivered-edge warning called that same port impossible.
 *
 * The result was the worst possible pairing: **the authoring API invited an
 * edge and the runtime then reported it as a mistake.** An agent that did
 * exactly what it was told was marked wrong.
 *
 * Two copies of one rule agree right up until someone edits one of them, and
 * nothing anywhere reports the divergence — the same reason the shared
 * conformance tables exist rather than hand-copied fixture rows. So the rule
 * lives here, in the engine, and `fancy-flow-mcp` calls it.
 *
 * Found by `flabs`, which had an agent configure a third case and then failed
 * the graph the authoring tools had led it to build.
 */
final class PortResolution
{
    /**
     * Every port this node could publish, given its kind and its config.
     *
     * Precedence, and it is deliberate:
     *
     *   1. the NODE's own declared `outputs` — the document is more specific
     *      than the kind;
     *   2. the kind's CONFIG-DERIVED ports, for the three kinds that have them;
     *   3. the kind's declared ports;
     *   4. `out`.
     *
     * @param  array<string,mixed> $config
     * @return list<string>
     */
    public static function possible(?FlowNode $node, ?NodeKind $kind, array $config = []): array
    {
        if ($node?->outputs !== null) {
            return array_values(array_map(
                static fn (PortDescriptor $p): string => $p->id,
                $node->outputs,
            ));
        }

        if ($kind === null) {
            // An unregistered kind is NOT ambiguous, which is worth stating
            // because it looks as though it should be: `activatedPorts` falls
            // back to exactly `out` for a kind it cannot resolve, so that is
            // what such a node publishes and nothing else.
            return ['out'];
        }

        $declared = array_values(array_map(
            static fn (PortDescriptor $p): string => $p->id,
            $kind->outputs ?? [],
        ));

        $derived = self::configDerived(KindId::bare($kind->name), $config, $declared);

        if ($derived !== []) {
            return $derived;
        }

        return $declared === [] ? ['out'] : $declared;
    }

    /**
     * The three kinds whose ports come from their own config.
     *
     * Returns `[]` when this kind has no config-derived ports, or when its
     * config does not yet declare any — an unconfigured `switch_case` falls
     * back to the kind's representative defaults rather than to nothing.
     *
     * @param  array<string,mixed> $config
     * @param  list<string>        $declared
     * @return list<string>
     */
    private static function configDerived(string $bareKind, array $config, array $declared): array
    {
        return match ($bareKind) {
            'switch_case' => self::switchCasePorts($config),
            'llm_router' => self::llmRouterPorts($config),
            'subflow' => self::subflowPorts($config, $declared),
            default => [],
        };
    }

    /**
     * `cases` is a map of VALUE => PORT ID, so the ports are its values.
     *
     * `default` is always present: `SwitchCaseExecutor` falls back to it for any
     * value with no matching entry, so it can publish even when no case names it.
     *
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private static function switchCasePorts(array $config): array
    {
        $cases = $config['cases'] ?? null;

        if (! is_array($cases) || $cases === []) {
            return [];
        }

        $ports = [];
        foreach ($cases as $port) {
            if (is_string($port) && $port !== '') {
                $ports[] = $port;
            }
        }

        if ($ports === []) {
            return [];
        }

        $ports[] = 'default';

        return array_values(array_unique($ports));
    }

    /**
     * One port per declared route, plus `fallback` unless it is switched off.
     *
     * `fallback` defaults ON — it is where a run goes when the model returns a
     * port that was never offered, and emitting on a port with no edge silently
     * ends the branch.
     *
     * @param array<string,mixed> $config
     * @return list<string>
     */
    private static function llmRouterPorts(array $config): array
    {
        $routes = $config['routes'] ?? null;

        if (! is_array($routes) || $routes === []) {
            return [];
        }

        $ports = [];
        foreach ($routes as $route) {
            if (is_array($route) && isset($route['port']) && $route['port'] !== '') {
                $ports[] = (string) $route['port'];
            }
        }

        if ($ports === []) {
            return [];
        }

        if (($config['fallback'] ?? true) !== false) {
            $ports[] = 'fallback';
        }

        return array_values(array_unique($ports));
    }

    /**
     * `subflow` gains `stream` in the streaming modes.
     *
     * @param  array<string,mixed> $config
     * @param  list<string>        $declared
     * @return list<string>
     */
    private static function subflowPorts(array $config, array $declared): array
    {
        $mode = (string) ($config['mode'] ?? 'output');

        if ($mode !== 'stream' && $mode !== 'both') {
            return [];
        }

        $ports = $declared === [] ? ['out'] : $declared;
        $ports[] = 'stream';

        return array_values(array_unique($ports));
    }
}
