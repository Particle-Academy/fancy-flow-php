<?php

declare(strict_types=1);

use FancyFlow\Analysis\TemplateReferences;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\Builtin;
use FancyFlow\Workflow;
use ParticleAcademy\Conformance\Conformance;

/**
 * `flow/template-references`, loaded rather than transcribed. The suite is new in
 * fancy-conformance 0.46.0. Until the dev pin moves there, this runs only when
 * FANCY_CONFORMANCE_ROOT names a checkout carrying it, and SKIPS with that reason
 * otherwise: it must never pass by finding no rows.
 */
it('matches the flow/template-references table on every row', function (): void {
    $suite = 'flow/template-references';
    $root = getenv('FANCY_CONFORMANCE_ROOT') ?: Conformance::root();

    if (! is_file($root.'/suites/'.$suite.'/cases.json')) {
        test()->markTestSkipped('fancy-conformance has no '.$suite.' yet: set FANCY_CONFORMANCE_ROOT or move the pin to 0.46.');
    }

    $cases = Conformance::cases($suite, $root);

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        $registry = new NodeKindRegistry;
        Builtin::register($registry, withStructural: true);
        $import = Workflow::import($case['input']['schema'], lenient: true, registry: $registry, templateReferences: TemplateReferences::OFF);

        $actual = TemplateReferences::findings($import->graph->nodes, $import->graph->edges, $registry);
        usort($actual, static fn (array $a, array $b): int => [$a['nodeId'], $a['configKey'], $a['path']] <=> [$b['nodeId'], $b['configKey'], $b['path']]);

        expect(array_map(static fn (array $f): array => [
            'nodeId' => $f['nodeId'], 'configKey' => $f['configKey'], 'path' => $f['path'], 'fields' => $f['fields'],
        ], $actual))->toBe($case['expected'], $case['id']);
    }
});
