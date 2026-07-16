<?php

declare(strict_types=1);

namespace FancyFlow\Laravel;

use FancyFlow\Attributes\FlowNode;
use ReflectionClass;

/**
 * Scans directories for executor classes carrying the {@see FlowNode} attribute.
 * Used by the service provider's boot + the `flow:discover` command to register
 * a co-located kind + executor in one place.
 */
final class FlowNodeDiscovery
{
    /**
     * @param list<string> $paths
     * @return list<array{attribute:FlowNode,class:class-string}>
     */
    public static function scan(array $paths): array
    {
        $roots = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false && is_dir($real)) {
                $roots[] = $real;
                foreach (self::phpFiles($real) as $file) {
                    require_once $file;
                }
            }
        }

        if ($roots === []) {
            return [];
        }

        $found = [];
        foreach (get_declared_classes() as $class) {
            $ref = new ReflectionClass($class);
            if (! $ref->isInstantiable()) {
                continue;
            }
            $file = $ref->getFileName();
            if ($file === false || ! self::within($file, $roots)) {
                continue;
            }
            $attributes = $ref->getAttributes(FlowNode::class);
            if ($attributes === []) {
                continue;
            }
            $found[] = ['attribute' => $attributes[0]->newInstance(), 'class' => $class];
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

    /** @param list<string> $roots */
    private static function within(string $file, array $roots): bool
    {
        $real = realpath($file);
        if ($real === false) {
            return false;
        }
        foreach ($roots as $root) {
            if (str_starts_with($real, $root.DIRECTORY_SEPARATOR) || $real === $root) {
                return true;
            }
        }

        return false;
    }
}
