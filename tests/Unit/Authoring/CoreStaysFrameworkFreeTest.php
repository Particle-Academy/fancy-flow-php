<?php

declare(strict_types=1);

use FancyFlow\Authoring\FlowAuthoring;

/**
 * Core's `require` is PHP-only, so nothing here may reach a framework or MCP.
 */
it('imports nothing outside FancyFlow and PHP', function (): void {
    $files = glob(dirname(__DIR__, 3).'/src/Authoring/*.php') ?: [];

    expect($files)->toHaveCount(4);

    foreach ($files as $file) {
        preg_match_all('/^use\s+([A-Za-z_\\\\]+)/m', (string) file_get_contents($file), $m);
        foreach ($m[1] as $import) {
            expect(str_starts_with($import, 'FancyFlow\\') || ! str_contains($import, '\\'))
                ->toBeTrue(basename($file).' imports '.$import);
        }
    }
});

it('reports a template reference to an undeclared field from validate', function (): void {
    $authoring = FlowAuthoring::default();
    $draft = $authoring->create('wf');
    $authoring->addNode($draft, 'manual_trigger', nodeId: 't');
    $authoring->addNode($draft, 'llm_call', nodeId: 'a', config: ['model' => 'm', 'prompt' => 'x']);
    $authoring->addNode($draft, 'log', nodeId: 'o', config: ['message' => '{{ in.txt }}']);
    $authoring->connect($draft, 't', 'a');
    $authoring->connect($draft, 'a', 'o');

    $result = $authoring->validate($draft);

    expect($result['ok'])->toBeTrue()
        ->and(implode(' ', array_column($result['warnings'], 'message')))->toContain('in.txt');
});
