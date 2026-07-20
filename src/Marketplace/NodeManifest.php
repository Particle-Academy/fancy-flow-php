<?php

declare(strict_types=1);

namespace FancyFlow\Marketplace;

/**
 * The node package manifest — the PHP twin of fancy-flow's `manifest` module.
 *
 * A node is not one artifact: it is a kind definition plus an executor for EACH
 * runtime the consumer runs. A package shipping only a TS executor is unusable
 * to anyone executing on PHP, and without a manifest that is invisible until a
 * run fails. Requested by the MOIC Suite consumer (fancy-flow#2 §2), who runs
 * the editor in TS and executes here.
 *
 * Validation must agree with the TS side, kind for kind — a package accepted by
 * one runtime's tooling and rejected by the other is worse than no check at all.
 *
 * ## Why the engine range lives per runtime
 *
 * The first cut carried ONE `fancyFlow` range, and it was wrong: the two
 * engines version independently, so a single range cannot say "needs ts >=0.15
 * AND php >=0.7". A package supporting both runtimes would install cleanly
 * against a host whose OTHER runtime was too old — the 0.9.0 failure shape
 * wearing a manifest.
 */
final class NodeManifest
{
    /** Must match fancy-flow's `NODE_MANIFEST_SCHEMA_VERSION`. */
    public const SCHEMA_VERSION = 1;

    /** Reserved for first-party packages; the registry rejects other claimants. */
    private const FIRST_PARTY_SCOPE = '@particle-academy/';

    /** `@scope/name` — the shape namespaced kind ids take. */
    private const NAMESPACED_KIND = '/^@[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/i';

    /** Durable runs RETRY, so a node that writes must say it is unsafe to replay. */
    private const SIDE_EFFECTS = ['none', 'idempotent', 'unsafe-to-replay'];

    private const REQUIREMENTS = ['required', 'optional'];

    /**
     * Validate a manifest.
     *
     * Returns EVERY problem rather than throwing on the first: an author fixing
     * a package wants the whole list, and a validator that reveals one error per
     * run turns a five-minute fix into five round trips.
     *
     * @param  mixed  $input decoded JSON
     * @return list<array{level:string,field:string,message:string}>
     */
    public static function validate(mixed $input): array
    {
        if (! is_array($input) || array_is_list($input)) {
            return [self::error('', 'Manifest must be a JSON object.')];
        }

        $problems = [];

        // Version first: an unknown version means every check below is guessing
        // at a shape we do not know, so say so instead of reporting confident
        // nonsense about the rest.
        $version = $input['schemaVersion'] ?? null;
        if ($version !== self::SCHEMA_VERSION) {
            if (! is_int($version)) {
                $problems[] = self::error('schemaVersion', 'Required, and must be '.self::SCHEMA_VERSION.'.');
            } else {
                return [self::error(
                    'schemaVersion',
                    "Unsupported manifest version {$version}; this fancy-flow understands ".self::SCHEMA_VERSION.'. Upgrade fancy-flow to install this node.',
                )];
            }
        }

        if (! is_string($input['name'] ?? null) || trim((string) $input['name']) === '') {
            $problems[] = self::error('name', 'Required — the package name as installed.');
        }

        self::validateKind($input['kind'] ?? null, $problems);

        // A leftover single range from the pre-per-runtime shape. Named
        // explicitly rather than ignored — silently it means "no constraint".
        if (array_key_exists('fancyFlow', $input)) {
            $problems[] = self::error(
                'fancyFlow',
                'A single engine range cannot express the split — it cannot say "needs ts >=0.15 AND php >=0.7". '
                .'Move the range into each entry of `runtimes` as `engine`.',
            );
        }

        self::validateAliases($input, $problems);

        if (array_key_exists('configVersion', $input) && ! is_int($input['configVersion'])) {
            $problems[] = self::error('configVersion', 'Must be an integer.');
        }

        if (array_key_exists('sideEffects', $input) && ! in_array($input['sideEffects'], self::SIDE_EFFECTS, true)) {
            $problems[] = self::error('sideEffects', 'Must be one of: '.implode(', ', self::SIDE_EFFECTS).'.');
        }

        self::validateRuntimes($input['runtimes'] ?? null, $problems);

        // The publish gate. Cross-runtime drift does not fail loudly — it
        // completes, down one path, with no error — so it has to be caught by
        // something that runs, not by review.
        if (! is_string($input['fixtures'] ?? null) || trim((string) $input['fixtures']) === '') {
            $problems[] = self::error(
                'fixtures',
                "Required — path to the node's golden fixtures. Every claimed runtime runs them, which is what makes cross-runtime parity verified rather than claimed.",
            );
        }

        self::validateCapabilities($input, $problems);

        if (array_key_exists('verified', $input)) {
            $problems[] = self::error(
                'verified',
                'Assigned by the registry, not the package. Remove it — a package cannot vouch for itself.',
            );
        }

        return $problems;
    }

    /** True when nothing in `validate()` was error-level. Warnings do not block. */
    public static function isValid(mixed $input): bool
    {
        foreach (self::validate($input) as $problem) {
            if ($problem['level'] === 'error') {
                return false;
            }
        }

        return true;
    }

    /**
     * Check a node against the runtimes a host executes on, and their versions.
     *
     * Two failures live here, both errors because the node genuinely cannot
     * run: a runtime the package does not implement, and one it implements
     * against an engine newer than the host's.
     *
     * An unchecked range is a WARNING rather than silence — "we did not check"
     * and "it is fine" must not look the same.
     *
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $hostRuntimes
     * @param  array<string,string>  $engineVersions
     * @return list<array{level:string,field:string,message:string}>
     */
    public static function checkRuntimeSupport(array $manifest, array $hostRuntimes, array $engineVersions = []): array
    {
        $runtimes = is_array($manifest['runtimes'] ?? null) ? $manifest['runtimes'] : [];
        $provided = array_keys($runtimes);
        $problems = [];
        $kind = (string) ($manifest['kind'] ?? 'this node');

        $missing = array_values(array_diff($hostRuntimes, $provided));
        if ($missing !== []) {
            $problems[] = self::error(
                'runtimes',
                $kind.' implements '.(implode(', ', $provided) ?: 'no runtime').
                ' but this project executes on '.implode(', ', $missing).
                '. The node would install, appear in the palette, and then fail to run.',
            );
        }

        foreach ($hostRuntimes as $runtime) {
            $spec = $runtimes[$runtime] ?? null;
            if (! is_array($spec)) {
                continue;
            }

            $range = (string) ($spec['engine'] ?? '');
            $hostVersion = $engineVersions[$runtime] ?? null;

            if ($hostVersion === null) {
                $problems[] = self::warning(
                    "runtimes.{$runtime}.engine",
                    "{$kind} needs {$runtime} engine {$range}; this host did not report its {$runtime} version, so the range was not checked.",
                );

                continue;
            }

            if (! self::satisfiesRange($hostVersion, $range)) {
                $problems[] = self::error(
                    "runtimes.{$runtime}.engine",
                    "{$kind} needs {$runtime} engine {$range}, but this host runs {$hostVersion}.",
                );
            }
        }

        return $problems;
    }

    /**
     * Check that every capability a node needs is wired.
     *
     * A `required` capability that is missing is an ERROR — that is the point of
     * the requirement level. It is meant to surface at AUTHOR time so an editor
     * can grey the node and name what the host never registered, rather than the
     * node installing cleanly and silently no-opping during a run.
     *
     * An `optional` one is a warning: the node still works, with less.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,bool>  $available
     * @return list<array{level:string,field:string,message:string}>
     */
    public static function checkCapabilities(array $manifest, array $available): array
    {
        $needed = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $kind = (string) ($manifest['kind'] ?? 'this node');
        $problems = [];

        foreach ($needed as $id => $requirement) {
            if (($available[$id] ?? false) === true) {
                continue;
            }

            $problems[] = $requirement === 'required'
                ? self::error('capabilities', "{$kind} requires the {$id} capability, which this host has not registered. The node cannot run here.")
                : self::warning('capabilities', "{$kind} can use the {$id} capability, which this host has not registered. The node runs with reduced behaviour.");
        }

        return $problems;
    }

    /**
     * Minimal semver range check — `^x.y.z`, `~x.y.z`, `>=x.y.z`, `x.y.z`, `*`.
     *
     * Deliberately small: this runs in tooling and CI, and pulling a semver
     * library into the engine for one comparison is not worth the dependency.
     * Anything it cannot parse is treated as UNSATISFIED rather than silently
     * passed, so an unparseable range fails loudly instead of waving a node
     * through. Must agree with the TS `satisfiesRange`.
     */
    public static function satisfiesRange(string $version, string $range): bool
    {
        $range = trim($range);
        if ($range === '*' || $range === '') {
            return true;
        }

        $v = self::parseVersion($version);
        if ($v === null) {
            return false;
        }

        foreach (explode('||', $range) as $clause) {
            if (self::satisfiesClause($v, trim($clause))) {
                return true;
            }
        }

        return false;
    }

    /** @param array{0:int,1:int,2:int} $v */
    private static function satisfiesClause(array $v, string $clause): bool
    {
        if (preg_match('/^(\^|~|>=|>|<=|<|=)?\s*v?(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $clause, $m) !== 1) {
            return false;
        }

        $op = $m[1] !== '' ? $m[1] : '=';
        $target = [(int) $m[2], (int) ($m[3] ?? 0), (int) ($m[4] ?? 0)];
        $cmp = self::compare($v, $target);

        return match ($op) {
            '>=' => $cmp >= 0,
            '>' => $cmp > 0,
            '<=' => $cmp <= 0,
            '<' => $cmp < 0,
            '=' => $cmp === 0,
            // Same major+minor, patch may rise.
            '~' => $cmp >= 0 && $v[0] === $target[0] && $v[1] === $target[1],
            // Below 1.0.0 a minor bump is breaking, so ^0.5 means 0.5.x — the
            // range every pre-1.0 package in this suite actually needs.
            '^' => $target[0] === 0
                ? $cmp >= 0 && $v[0] === 0 && $v[1] === $target[1]
                : $cmp >= 0 && $v[0] === $target[0],
            default => false,
        };
    }

    /** @return array{0:int,1:int,2:int}|null */
    private static function parseVersion(string $version): ?array
    {
        if (preg_match('/^v?(\d+)\.(\d+)(?:\.(\d+))?/', trim($version), $m) !== 1) {
            return null;
        }

        return [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];
    }

    /**
     * @param  array{0:int,1:int,2:int}  $a
     * @param  array{0:int,1:int,2:int}  $b
     */
    private static function compare(array $a, array $b): int
    {
        for ($i = 0; $i < 3; $i++) {
            if ($a[$i] !== $b[$i]) {
                return $a[$i] < $b[$i] ? -1 : 1;
            }
        }

        return 0;
    }

    /** @param list<array{level:string,field:string,message:string}> $problems */
    private static function validateKind(mixed $kind, array &$problems): void
    {
        if (! is_string($kind) || trim($kind) === '') {
            $problems[] = self::error('kind', 'Required — the canonical kind id this package provides.');

            return;
        }

        if (preg_match(self::NAMESPACED_KIND, $kind) !== 1) {
            // The one mistake that cannot be repaired: the ambiguous string is
            // already written into saved documents.
            $problems[] = self::error(
                'kind',
                "\"{$kind}\" must be namespaced as @scope/name — a bare id makes stored graphs ambiguous, and that is unfixable once documents carry it.",
            );

            return;
        }

        if (str_starts_with($kind, self::FIRST_PARTY_SCOPE)) {
            $problems[] = self::warning(
                'kind',
                self::FIRST_PARTY_SCOPE.'* is reserved for first-party nodes; the registry will reject this unless the package is first-party.',
            );
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array{level:string,field:string,message:string}>  $problems
     */
    private static function validateAliases(array $input, array &$problems): void
    {
        if (! array_key_exists('aliases', $input)) {
            return;
        }

        $aliases = $input['aliases'];
        $bad = ! is_array($aliases) || ! array_is_list($aliases);

        if (! $bad) {
            foreach ($aliases as $alias) {
                if (! is_string($alias) || trim($alias) === '') {
                    $bad = true;
                    break;
                }
            }
        }

        if ($bad) {
            $problems[] = self::error('aliases', 'Must be an array of non-empty id strings.');
        }
    }

    /** @param list<array{level:string,field:string,message:string}> $problems */
    private static function validateRuntimes(mixed $runtimes, array &$problems): void
    {
        // Emptiness is checked BEFORE shape: json_decode turns both `{}` and
        // `[]` into the same empty PHP array, so an empty runtimes object would
        // otherwise be reported as "not an object" — true of the decoded value,
        // and useless to the author, who wrote `{}`.
        if (is_array($runtimes) && $runtimes === []) {
            $problems[] = self::error('runtimes', 'A node that implements no runtime cannot execute anywhere.');

            return;
        }

        if (! is_array($runtimes) || array_is_list($runtimes)) {
            $problems[] = self::error('runtimes', 'Required — an object of runtime id to { entry | package, engine }.');

            return;
        }

        foreach ($runtimes as $runtime => $spec) {
            if (! is_array($spec) || array_is_list($spec)) {
                $problems[] = self::error("runtimes.{$runtime}", 'Must be an object of { entry | package, engine }.');

                continue;
            }

            $hasEntry = is_string($spec['entry'] ?? null) && trim((string) $spec['entry']) !== '';
            $hasPackage = is_string($spec['package'] ?? null) && trim((string) $spec['package']) !== '';

            if (! $hasEntry && ! $hasPackage) {
                $problems[] = self::error("runtimes.{$runtime}", 'Needs `entry` (a module path) or `package` (a dependency requirement).');
            }
            if ($hasEntry && $hasPackage) {
                $problems[] = self::error("runtimes.{$runtime}", 'Declare `entry` or `package`, not both — which one is authoritative is otherwise ambiguous.');
            }
            if (! is_string($spec['engine'] ?? null) || trim((string) $spec['engine']) === '') {
                $problems[] = self::error(
                    "runtimes.{$runtime}.engine",
                    "Required — the semver range of the {$runtime} engine. Without it, this node installs against a {$runtime} engine too old to run it.",
                );
            }
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<array{level:string,field:string,message:string}>  $problems
     */
    private static function validateCapabilities(array $input, array &$problems): void
    {
        if (! array_key_exists('capabilities', $input)) {
            return;
        }

        $caps = $input['capabilities'];

        if (! is_array($caps) || array_is_list($caps)) {
            $problems[] = self::error(
                'capabilities',
                'Must be an object of capability id to "required" | "optional" — a bare list cannot say whether the node works without one.',
            );

            return;
        }

        foreach ($caps as $id => $requirement) {
            if (! in_array($requirement, self::REQUIREMENTS, true)) {
                $problems[] = self::error("capabilities.{$id}", 'Must be "required" or "optional".');
            }
        }
    }

    /** @return array{level:string,field:string,message:string} */
    private static function error(string $field, string $message): array
    {
        return ['level' => 'error', 'field' => $field, 'message' => $message];
    }

    /** @return array{level:string,field:string,message:string} */
    private static function warning(string $field, string $message): array
    {
        return ['level' => 'warning', 'field' => $field, 'message' => $message];
    }
}
