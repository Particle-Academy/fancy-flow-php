<?php

declare(strict_types=1);

use FancyFlow\Capabilities\LlmClientDetector;

/**
 * Every install instruction this package prints names the MAINTAINED Prism.
 *
 * ## The defect
 *
 * The README gets this right — it says `composer require particle-academy/prism`,
 * explains that it is the maintained fork of `prism-php/prism` (unshipped since
 * March 2026), and warns that installing BOTH leaves you with two copies of
 * every `Prism\Prism\*` class because the fork declares no `replace`.
 *
 * The runtime strings said the opposite. `LlmClientDetector` told people to run
 * `composer require prism-php/prism`, and so did the config comment and the
 * adapter's exception.
 *
 * Those are the ones that get followed. A README is read once, before anything
 * breaks; an error message is read at the exact moment someone is stuck and
 * looking for the next command to type. Ours handed them the unmaintained
 * package the README had just warned them off — and for anyone who already had
 * the fork, the fix it suggested would have installed the duplicate-namespace
 * state the README calls out by name.
 *
 * Found by an agent in another workspace who read the config comment, proposed
 * the upstream library, and was corrected by its owner. The correction should
 * not have been necessary; our own file sent them there.
 *
 * ## Why it asserts the absence too
 *
 * Adding the right name while leaving the wrong one is the likeliest partial
 * fix, and it would still print a command that installs the wrong package.
 */
it('never tells anyone to install the unmaintained upstream', function (): void {
    $paths = [
        'LlmClientDetector' => __DIR__.'/../../src/Capabilities/LlmClientDetector.php',
        'PrismLlmClient' => __DIR__.'/../../src/Capabilities/Adapters/PrismLlmClient.php',
        'config/fancy-flow.php' => __DIR__.'/../../config/fancy-flow.php',
    ];

    foreach ($paths as $label => $path) {
        // ASSERT THE READ, then assert the content.
        //
        // The first version of this test did `expect(file_get_contents($p))`
        // straight through, and `file_get_contents` returning `false` makes
        // `->not->toContain(...)` pass vacuously. It reported green against a
        // file that provably contained the offending string, which is the
        // failure this whole test exists to prevent, one level up.
        expect($path)->toBeFile();

        $source = file_get_contents($path);

        expect($source)->toBeString()->not->toBeEmpty();

        // ONE needle, and a plain assertion for the message. Pest's
        // `toContain()` is variadic, so a second argument passed as a "message"
        // is read as ANOTHER NEEDLE and quietly changes what is being asserted.
        expect(str_contains($source, 'composer require prism-php/prism'))
            ->toBeFalse("{$label} prints an install command for the unmaintained upstream.");
    }
});

it('names the maintained package where it gives an install instruction', function (): void {
    // The POSITIVE half. Removing the wrong name without adding the right one
    // is the likeliest partial fix and would leave someone stuck with no
    // command at all — which the negative test above would happily pass.
    //
    // Asserted against the SOURCE rather than against `unavailableMessage()`,
    // because that method branches on what happens to be installed: in this
    // repo both Prism and laravel/ai are present, so it returns the
    // "both installed" text and never reaches an install hint. A test whose
    // subject depends on the vendor directory is a test that means something
    // different on someone else's machine.
    $paths = [
        'LlmClientDetector' => __DIR__.'/../../src/Capabilities/LlmClientDetector.php',
        'PrismLlmClient' => __DIR__.'/../../src/Capabilities/Adapters/PrismLlmClient.php',
    ];

    foreach ($paths as $label => $path) {
        expect($path)->toBeFile();

        $source = file_get_contents($path);

        expect($source)->toBeString()->not->toBeEmpty();
        expect(str_contains($source, 'particle-academy/prism'))
            ->toBeTrue("{$label} tells nobody which Prism to install.");
    }
});
