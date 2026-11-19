<?php

declare(strict_types=1);

use FancyFlow\Workflow;
use Illuminate\Support\Facades\Artisan;

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
    $code = $this->artisan('flow:list-kinds');
    $output = Artisan::output();

    expect($output)->toContain('manual_trigger')
        ->and($output)->toContain('27 kinds registered.')
        ->and($code)->toBe(0);
});

it('validates a workflow file', function () {
    $file = tempSchema([['id' => 't', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0]]]);

    expect($this->artisan('flow:validate', ['file' => $file]))->toBe(0);

    @unlink($file);
});

it('fails validation on an unknown kind', function () {
    $file = tempSchema([['id' => 'x', 'kind' => 'bogus_kind', 'position' => ['x' => 0, 'y' => 0]]]);

    expect($this->artisan('flow:validate', ['file' => $file]))->not->toBe(0);

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

    expect($this->artisan('flow:run', ['workflow' => $file, '--input' => '{"t":{"hello":"world"}}']))->toBe(0);

    @unlink($file);
});
