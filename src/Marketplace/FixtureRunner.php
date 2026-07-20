<?php

declare(strict_types=1);

namespace FancyFlow\Marketplace;

use FancyFlow\Contracts\NodeExecutor;
use FancyFlow\Engine\FlowRunner;
use FancyFlow\ExecutorRegistry;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\Pause;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\PortDescriptor;

/**
 * Runs a node's golden fixtures on the PHP runtime — the twin of fancy-flow's
 * `runFixtures`.
 *
 * The same JSON cases run on both runtimes. That is the entire parity
 * guarantee, and the reason fixtures are required to publish rather than
 * encouraged: cross-runtime drift does not fail loudly. The 0.9.0
 * port-resolution divergence produced flows that routed correctly in the editor
 * and silently down one path here — status `completed`, no error, nothing to
 * alert on.
 *
 * WHAT A CASE ASSERTS: that THE DOWNSTREAM NODE EXECUTED, not the port the node
 * recorded. A test reading back the recorded port stays green while no edge
 * fires and the run halts at the branch. So this wires a real probe to every
 * declared port and reports which probes actually ran.
 */
final class FixtureRunner
{
    private const SUBJECT = 'subject';

    private const PROBE = 'probe:';

    /**
     * @param  array<string,mixed>  $file  decoded fixture file: {kind, cases[]}
     * @return array{ok:bool,passed:int,failures:list<array{case:string,message:string}>}
     */
    public function run(array $file, NodeExecutor|callable $executor): array
    {
        $kind = (string) ($file['kind'] ?? '');
        $failures = [];
        $passed = 0;

        foreach ($file['cases'] ?? [] as $case) {
            $name = (string) ($case['name'] ?? 'unnamed');
            $caseFailures = $this->runCase($kind, $case, $executor);

            if ($caseFailures === []) {
                $passed++;

                continue;
            }

            foreach ($caseFailures as $message) {
                $failures[] = ['case' => $name, 'message' => $message];
            }
        }

        return ['ok' => $failures === [], 'passed' => $passed, 'failures' => $failures];
    }

    /**
     * @param  array<string,mixed>  $case
     * @return list<string>
     */
    private function runCase(string $kind, array $case, NodeExecutor|callable $executor): array
    {
        $registry = Builtin::register(new NodeKindRegistry(), withStructural: true);
        $nodeKind = $registry->get($kind);

        $config = $case['config'] ?? [];
        $subject = new FlowNode(id: self::SUBJECT, type: $kind, config: is_array($config) ? $config : []);

        // A case may declare the node's resolved output ports.
        //
        // This is NOT a convenience. TS derives config-driven ports by running a
        // JavaScript function (`switch_case`'s cases, `llm_router`'s routes);
        // PHP cannot, and falls back to the kind's static declaration. Left
        // there, the identical fixture would build a different graph on each
        // runtime — the fixtures would silently stop comparing like with like,
        // which is the exact failure they exist to catch.
        //
        // So a case states its ports the same way an exported document does
        // (see 0.10.1, "serialize resolved ports"), and both runners honour it.
        $declared = is_array($case['ports'] ?? null)
            ? array_values(array_map(strval(...), $case['ports']))
            : array_map(static fn (PortDescriptor $p): string => $p->id, $nodeKind?->outputs ?? []);

        $ports = $declared === [] ? ['out'] : $declared;

        $nodes = [
            new FlowNode(id: 'trigger', type: 'manual_trigger'),
            $subject,
        ];
        $edges = [new FlowEdge(id: 'e:trigger', source: 'trigger', target: self::SUBJECT)];

        foreach ($ports as $port) {
            $nodes[] = new FlowNode(id: self::PROBE.$port, type: 'transform');
            $edges[] = new FlowEdge(
                id: 'e:'.$port,
                source: self::SUBJECT,
                target: self::PROBE.$port,
                sourceHandle: $port,
            );
        }

        $fired = [];
        $carried = null;

        $executors = new ExecutorRegistry();
        $executors->bind('manual_trigger', static fn () => $case['inputs'] ?? []);
        $executors->bind($kind, $executor);

        // Bound by NODE ID so each probe reports its own port. Recording here,
        // rather than inspecting the subject's return value, is what makes this
        // assert reachability instead of intent.
        foreach ($ports as $port) {
            $executors->bindNode(
                self::PROBE.$port,
                static function (ExecutionContext $ctx) use ($port, &$fired, &$carried) {
                    $fired[] = $port;
                    $carried = $ctx->input('in', $ctx->inputs);

                    return null;
                },
            );
        }

        $result = (new FlowRunner())->run(new FlowGraph($nodes, $edges), $executors);
        $expect = is_array($case['expect'] ?? null) ? $case['expect'] : [];

        return $this->assert($expect, $result->ok, $result->error, $fired, $carried);
    }

    /**
     * @param  array<string,mixed>  $expect
     * @param  list<string>  $fired
     * @return list<string>
     */
    private function assert(array $expect, bool $ok, ?string $error, array $fired, mixed $carried): array
    {
        $problems = [];

        if (isset($expect['pause'])) {
            $wanted = $expect['pause'];
            $pause = Pause::decode($error);

            if ($pause === null) {
                return ['expected a pause awaiting "'.($wanted['awaiting'] ?? '?').'", got '.
                    ($ok ? 'a completed run' : 'error: '.(string) $error)];
            }
            if (($wanted['awaiting'] ?? null) !== $pause->awaiting) {
                $problems[] = 'expected pause awaiting "'.($wanted['awaiting'] ?? '?').'", got "'.$pause->awaiting.'"';
            }
            if (array_key_exists('detail', $wanted) && $wanted['detail'] !== $pause->detail) {
                $problems[] = 'pause detail mismatch: expected '.json_encode($wanted['detail']).
                    ', got '.json_encode($pause->detail);
            }

            return $problems;
        }

        if (isset($expect['error'])) {
            if ($ok) {
                return ['expected an error containing "'.$expect['error'].'", but the run succeeded'];
            }
            if (! str_contains((string) $error, (string) $expect['error'])) {
                return ['expected an error containing "'.$expect['error'].'", got "'.(string) $error.'"'];
            }

            return [];
        }

        if (isset($expect['ports'])) {
            $got = $fired;
            $want = $expect['ports'];
            sort($got);
            sort($want);

            if ($got !== $want) {
                // Named in terms of reachability, because that is what the
                // assertion means and what a reader needs to act on.
                $problems[] = 'expected these ports to reach a downstream node: ['.implode(', ', $want).
                    '], but ['.implode(', ', $got).'] did'.($ok ? '' : ' (run error: '.(string) $error.')');
            }
        }

        if (array_key_exists('value', $expect) && $expect['value'] !== $carried) {
            $problems[] = 'expected the value carried downstream to be '.json_encode($expect['value']).
                ', got '.json_encode($carried);
        }

        return $problems;
    }

    /**
     * Validate a fixture file's shape before running it.
     *
     * A package publishing an empty or malformed fixture file has satisfied the
     * letter of the requirement and none of its purpose.
     *
     * @return list<string>
     */
    public static function validateFile(mixed $input, ?string $expectedKind = null): array
    {
        if (! is_array($input) || array_is_list($input)) {
            return ['Fixture file must be a JSON object.'];
        }

        $problems = [];
        $kind = $input['kind'] ?? null;

        if (! is_string($kind) || trim($kind) === '') {
            $problems[] = '`kind` is required — the kind these cases exercise.';
        } elseif ($expectedKind !== null && $kind !== $expectedKind) {
            $problems[] = "`kind` is \"{$kind}\" but the manifest declares \"{$expectedKind}\".";
        }

        $cases = $input['cases'] ?? null;
        if (! is_array($cases) || $cases === []) {
            $problems[] = '`cases` must contain at least one case — an empty fixture file proves nothing.';

            return $problems;
        }

        foreach (array_values($cases) as $i => $case) {
            $label = "cases[{$i}]";

            if (! is_array($case)) {
                $problems[] = "{$label} must be an object.";

                continue;
            }
            if (! is_string($case['name'] ?? null) || trim((string) $case['name']) === '') {
                $problems[] = "{$label}.name is required — a failure report names it.";
            }
            if (! is_array($case['expect'] ?? null)) {
                $problems[] = "{$label}.expect is required — a case that asserts nothing passes vacuously.";

                continue;
            }

            $expect = $case['expect'];
            $asserts = array_filter(
                ['ports', 'value', 'pause', 'error'],
                static fn (string $key): bool => array_key_exists($key, $expect),
            );
            if ($asserts === []) {
                $problems[] = "{$label}.expect must assert at least one of: ports, value, pause, error.";
            }
            if (array_key_exists('ports', $expect) && ! is_array($expect['ports'])) {
                $problems[] = "{$label}.expect.ports must be an array of port id strings.";
            }
        }

        return $problems;
    }
}
