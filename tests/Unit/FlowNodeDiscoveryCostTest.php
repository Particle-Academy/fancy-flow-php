<?php

declare(strict_types=1);

/**
 * The cost of a scan follows the nodes on disk, not the size of the process.
 *
 * ## The defect (issue #15)
 *
 * `scan()` found the classes under its paths by reflecting EVERY class declared
 * in the PHP process and keeping the ones whose file sat under a root. A test
 * suite boots the app once per test and never un-declares a class (every model,
 * mock and test class stays), so each boot inspected more than the last for the
 * same result. Measured with 23 nodes over 2,000 boots: 7 ms per boot with
 * discovery off, and with it on, 38 ms rising to 461 ms per boot. The host's
 * ~3,300-test suite could not finish.
 *
 * ## Why a subprocess, and why the thresholds are shaped like this
 *
 * The test declares 20,000 unrelated classes, which can never be removed. Doing
 * that in the suite's own process would slow every test after it. A child
 * process takes the hit and exits.
 *
 * It compares the best of several scans before and after those classes exist.
 * Against the old scan, on Windows, that was 0.37 ms before and 445 ms after. I
 * have not measured another OS: the per-class cost there should be lower, but
 * twenty thousand classes against a sub-millisecond baseline leaves a wide
 * margin. The allowance is three times the earlier figure plus one millisecond,
 * so scheduler noise on a scan that small cannot fail it.
 */
it('does not slow down as unrelated classes are declared', function () {
    $script = <<<'PHP'
    <?php
    require $argv[1].'/vendor/autoload.php';
    $dir = $argv[2];

    $best = static function () use ($dir): float {
        $best = INF;
        for ($i = 0; $i < 7; $i++) {
            $start = hrtime(true);
            $found = FancyFlow\Laravel\FlowNodeDiscovery::scan([$dir]);
            $best = min($best, (hrtime(true) - $start) / 1e6);
        }
        if (count($found) !== 4) {
            fwrite(STDERR, 'expected 4 discovered nodes, got '.count($found));
            exit(3);
        }

        return $best;
    };

    $best(); // the first scan includes the files; only repeat boots are compared
    $before = $best();
    for ($n = 0; $n < 20000; $n++) {
        eval("namespace UnrelatedToDiscovery; final class Declared{$n} {}");
    }
    $after = $best();

    echo json_encode(['before' => $before, 'after' => $after, 'declared' => count(get_declared_classes())]);
    PHP;

    // Written to a file and started without a shell: Windows' escapeshellarg
    // turns every double quote in an inline script into a space.
    $file = tempnam(sys_get_temp_dir(), 'ff-discovery-cost-').'.php';
    file_put_contents($file, $script);

    try {
        $process = proc_open(
            [PHP_BINARY, '-d', 'memory_limit=512M', $file, dirname(__DIR__, 2), dirname(__DIR__).'/Fixtures/DiscoveredNodes'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $raw = stream_get_contents($pipes[1]);
        $errors = stream_get_contents($pipes[2]);
        $exitCode = proc_close($process);
    } finally {
        @unlink($file);
    }

    expect($exitCode)->toBe(0, "the probe process failed: {$errors}{$raw}");

    $timing = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    expect($timing['declared'])->toBeGreaterThan(20000);

    $allowed = $timing['before'] * 3 + 1.0;
    expect($timing['after'])->toBeLessThan(
        $allowed,
        sprintf('a scan took %.2f ms with %d classes declared, against %.2f ms before they existed',
            $timing['after'], $timing['declared'], $timing['before']),
    );
});
