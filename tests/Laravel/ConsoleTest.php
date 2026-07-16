<?php

declare(strict_types=1);

use FancyFlow\Workflow;

/** Write a schema to a temp file and return its path. */
function tempSchema(array $nodes, array $edges = []): string
{
    $file = tempnam(sys_get_temp_dir(), 'ff_').'.json';
    file_put_contents($file, json_encode([
        '$schema' => Workflow::SCHEMA_URL,
        'version' => 1,
        'graph' => ['nodes' => $nodes, 'edges' => $edges],
    ]));

    return $file;
}

it('lists registered kinds', function () {
    $this->artisan('flow:list-kinds')
        ->expectsOutputToContain('manual_trigger')
        ->expectsOutputToContain('24 kinds registered.')
        ->assertSuccessful();
});

it('validates a workflow file', function () {
    $file = tempSchema([['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]]);

    $this->artisan('flow:validate', ['file' => $file])->assertSuccessful();

    @unlink($file);
});

it('fails validation on an unknown kind', function () {
    $file = tempSchema([['id' => 'x', 'kind' => 'bogus_kind', 'position' => ['x' => 0, 'y' => 0]]]);

    $this->artisan('flow:validate', ['file' => $file])->assertFailed();

    @unlink($file);
});

it('runs a workflow file', function () {
    $file = tempSchema(
        [
            ['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'o', 'kind' => 'output', 'position' => ['x' => 1, 'y' => 0]],
        ],
        [['id' => 'e1', 'source' => 't', 'target' => 'o']],
    );

    $this->artisan('flow:run', ['workflow' => $file, '--input' => '{"t":{"hello":"world"}}'])
        ->assertSuccessful();

    @unlink($file);
});
