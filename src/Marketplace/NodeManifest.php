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
 */
final class NodeManifest
{
    /** Must match fancy-flow's `NODE_MANIFEST_SCHEMA_VERSION`. */
    public const SCHEMA_VERSION = 1;

    /** Reserved for first-party packages; the registry rejects other claimants. */
    private const FIRST_PARTY_SCOPE = '@particle-academy/';

    /** `@scope/name` — the shape namespaced kind ids take. */
    private const NAMESPACED_KIND = '/^@[a-z0-9][a-z0-9._-]*\/[a-z0-9][a-z0-9._-]*$/i';

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

        $kind = $input['kind'] ?? null;
        if (! is_string($kind) || trim($kind) === '') {
            $problems[] = self::error('kind', 'Required — the canonical kind id this package provides.');
        } elseif (preg_match(self::NAMESPACED_KIND, $kind) !== 1) {
            $problems[] = self::error(
                'kind',
                "\"{$kind}\" must be namespaced as @scope/name — a bare id makes stored graphs ambiguous, and that is unfixable once documents carry it.",
            );
        } elseif (str_starts_with($kind, self::FIRST_PARTY_SCOPE)) {
            $problems[] = self::warning(
                'kind',
                self::FIRST_PARTY_SCOPE.'* is reserved for first-party nodes; the registry will reject this unless the package is first-party.',
            );
        }

        if (! is_string($input['fancyFlow'] ?? null) || trim((string) $input['fancyFlow']) === '') {
            $problems[] = self::error('fancyFlow', 'Required — the semver range of fancy-flow this node targets.');
        }

        $runtimes = $input['runtimes'] ?? null;
        // Emptiness is checked BEFORE shape: json_decode turns both `{}` and
        // `[]` into the same empty PHP array, so an empty runtimes object would
        // otherwise be reported as "not an object" — technically true of the
        // decoded value, and useless to the author, who wrote `{}`.
        if (is_array($runtimes) && $runtimes === []) {
            $problems[] = self::error('runtimes', 'A node that implements no runtime cannot execute anywhere.');
        } elseif (! is_array($runtimes) || array_is_list($runtimes)) {
            $problems[] = self::error('runtimes', 'Required — an object of runtime id to entrypoint.');
        } else {
            foreach ($runtimes as $runtime => $entry) {
                if (! is_string($entry) || trim($entry) === '') {
                    $problems[] = self::error("runtimes.{$runtime}", 'Entrypoint must be a non-empty string.');
                }
            }
        }

        // The publish gate. Cross-runtime drift does not fail loudly — it
        // completes, down one path, with no error — so it has to be caught by
        // something that runs, not by review.
        if (! is_string($input['fixtures'] ?? null) || trim((string) $input['fixtures']) === '') {
            $problems[] = self::error(
                'fixtures',
                "Required — path to the node's golden fixtures. Every claimed runtime runs them, which is what makes cross-runtime parity verified rather than claimed.",
            );
        }

        if (array_key_exists('capabilities', $input)) {
            $caps = $input['capabilities'];
            if (! is_array($caps) || ! array_is_list($caps) || array_filter($caps, static fn ($c) => ! is_string($c)) !== []) {
                $problems[] = self::error('capabilities', 'Must be an array of capability id strings.');
            }
        }

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
     * Check a node against the runtimes a host actually executes on.
     *
     * What makes a TS-only package visible to a PHP host BEFORE install rather
     * than at the first run. An error, not a warning: the node genuinely cannot
     * execute there.
     *
     * @param  array<string,mixed>  $manifest
     * @param  list<string>  $hostRuntimes
     * @return list<array{level:string,field:string,message:string}>
     */
    public static function checkRuntimeSupport(array $manifest, array $hostRuntimes): array
    {
        $provided = array_keys(is_array($manifest['runtimes'] ?? null) ? $manifest['runtimes'] : []);
        $missing = array_values(array_diff($hostRuntimes, $provided));

        if ($missing === []) {
            return [];
        }

        $kind = (string) ($manifest['kind'] ?? 'this node');

        return [self::error(
            'runtimes',
            $kind.' implements '.(implode(', ', $provided) ?: 'no runtime').
            ' but this project executes on '.implode(', ', $missing).
            '. The node would install, appear in the palette, and then fail to run.',
        )];
    }

    /**
     * Check that every capability a node needs is wired.
     *
     * A warning rather than an error — install is when you learn what to wire,
     * not a reason to refuse, since wiring usually happens afterwards.
     *
     * @param  array<string,mixed>  $manifest
     * @param  array<string,bool>  $available
     * @return list<array{level:string,field:string,message:string}>
     */
    public static function checkCapabilities(array $manifest, array $available): array
    {
        $needed = is_array($manifest['capabilities'] ?? null) ? $manifest['capabilities'] : [];
        $missing = array_values(array_filter($needed, static fn ($c) => ($available[$c] ?? false) !== true));

        if ($missing === []) {
            return [];
        }

        return [self::warning(
            'capabilities',
            (string) ($manifest['kind'] ?? 'this node').' needs '.implode(', ', $missing).
            ' wired on the host. Until then the node will fail at run time rather than at install.',
        )];
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
