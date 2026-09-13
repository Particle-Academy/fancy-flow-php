<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Attributes\FlowNode;
use PhpToken;
use ReflectionClass;

/**
 * Scans directories for executor classes carrying the {@see FlowNode} attribute.
 * Used by the service provider's boot + the `flow:discover` command to register
 * a co-located kind + executor in one place.
 *
 * ## Cost follows the files, never the process
 *
 * Until 0.51.1 this found its classes by reflecting EVERY class declared in the
 * process and keeping those whose file sat under a root. A test suite boots the
 * app once per test and never un-declares anything, so each boot inspected more
 * classes than the last for the same result. Measured with 23 nodes over 2,000
 * boots, a boot went from 38 ms to 461 ms while discovery off stayed at 7 ms,
 * and a host's 3,300-test suite could not finish (issue #15).
 *
 * So it now reads the class names each walked file DECLARES and reflects only
 * those. Work per boot is the directory walk plus one reflection per declared
 * class, however many classes the process holds.
 *
 * Reading declarations rather than diffing `get_declared_classes()` around the
 * `require_once` loop is deliberate: a class a host autoloaded before this ran
 * is declared already, so a diff would silently drop it.
 */
final class FlowNodeDiscovery
{
    /**
     * Class names each file declares, keyed by path.
     *
     * Safe to keep for the life of the process: PHP cannot re-declare a class,
     * so re-reading an edited file could only name classes that will never
     * exist. It is what keeps a repeat boot from re-reading every node file.
     *
     * @var array<string,list<string>>
     */
    private static array $declaredIn = [];

    /**
     * @param list<string> $paths
     * @return list<array{attribute:FlowNode,class:class-string}>
     */
    public static function scan(array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                foreach (self::phpFiles($real) as $file) {
                    $files[] = $file;
                    require_once $file;
                }
            }
        }

        $found = [];
        $seen = [];
        foreach ($files as $file) {
            foreach (self::classesDeclaredIn($file) as $class) {
                // Overlapping paths walk the same file twice, and a declaration
                // behind a condition that never ran leaves a name with no class.
                if (isset($seen[$class]) || ! class_exists($class, false)) {
                    continue;
                }
                $seen[$class] = true;

                $ref = new ReflectionClass($class);
                if (! $ref->isInstantiable()) {
                    continue;
                }
                $attributes = $ref->getAttributes(FlowNode::class);
                if ($attributes === []) {
                    continue;
                }
                $found[] = ['attribute' => $attributes[0]->newInstance(), 'class' => $class];
            }
        }

        return $found;
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($it as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * The fully-qualified names of the classes a file declares.
     *
     * Only named `class` declarations count. Interfaces, traits and enums are
     * never instantiable, so they could not be nodes. `Foo::class` and
     * `new class {}` contain the keyword but declare nothing, and neither is
     * followed by a name.
     *
     * @return list<string>
     */
    private static function classesDeclaredIn(string $file): array
    {
        if (isset(self::$declaredIn[$file])) {
            return self::$declaredIn[$file];
        }

        // Readable by construction: `scan()` has already required it.
        $tokens = array_values(array_filter(
            PhpToken::tokenize((string) file_get_contents($file)),
            static fn (PhpToken $token): bool => ! $token->isIgnorable(),
        ));

        $namespace = '';
        $classes = [];
        foreach ($tokens as $i => $token) {
            if ($token->is(T_NAMESPACE)) {
                // `namespace Foo\Bar;` or `namespace Foo\Bar {`; a bare
                // `namespace {` opens the global namespace.
                $name = $tokens[$i + 1] ?? null;
                $namespace = $name !== null && $name->is([T_STRING, T_NAME_QUALIFIED]) ? $name->text.'\\' : '';

                continue;
            }

            if (! $token->is(T_CLASS)) {
                continue;
            }
            $name = $tokens[$i + 1] ?? null;
            if ($name === null || ! $name->is(T_STRING)) {
                continue;
            }
            $classes[] = $namespace.$name->text;
        }

        return self::$declaredIn[$file] = $classes;
    }
}
