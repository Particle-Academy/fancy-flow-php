<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

use FancyFlow\Capabilities\Adapters\LaravelAiLlmClient;
use FancyFlow\Capabilities\Adapters\PrismLlmClient;

/**
 * Wires `llm_router` to whichever supported LLM library the app already has.
 *
 * A bare contract every host must implement is not a working primitive, so the
 * shipped adapters get detected and used automatically. The rules:
 *
 *   - exactly one supported library installed → use it, no configuration;
 *   - both installed → `driver` in config decides; without it, detection
 *     returns null so the node ABORTS with a message rather than silently
 *     picking a provider the author didn't choose;
 *   - neither installed → null, and the message names what to install.
 *
 * Detection is `class_exists()`-guarded throughout, so neither library is a
 * runtime dependency of this package (both live under composer `suggest`).
 */
final class LlmClientDetector
{
    public const PRISM = 'prism';

    public const LARAVEL_AI = 'laravel-ai';

    /**
     * Which supported libraries are installed right now, in canonical order.
     *
     * @return list<string>
     */
    public static function available(): array
    {
        $available = [];
        if (PrismLlmClient::isAvailable()) {
            $available[] = self::PRISM;
        }
        if (LaravelAiLlmClient::isAvailable()) {
            $available[] = self::LARAVEL_AI;
        }

        return $available;
    }

    /**
     * Build an adapter for the app's installed library, or null when the choice
     * isn't unambiguous. Never guesses.
     *
     * @param array<string,mixed> $config `driver`, `provider`, `model`, `credentials`
     */
    public static function detect(array $config = []): ?LlmClient
    {
        $available = self::available();
        $driver = self::driver($config);

        if ($driver !== null) {
            // An explicit driver that isn't installed is a configuration error,
            // not a reason to fall back to the other one.
            return in_array($driver, $available, true) ? self::make($driver, $config) : null;
        }

        return count($available) === 1 ? self::make($available[0], $config) : null;
    }

    /**
     * Why detection came up empty, phrased as the next action to take.
     *
     * @param array<string,mixed> $config
     */
    public static function unavailableMessage(array $config = []): string
    {
        $available = self::available();
        $driver = self::driver($config);

        if ($driver !== null && ! in_array($driver, $available, true)) {
            return sprintf(
                'llm_router: the configured LLM driver "%s" is not installed. %s',
                $driver,
                self::installHint($driver),
            );
        }

        if (count($available) > 1) {
            return 'llm_router: both prism-php/prism and laravel/ai are installed, so fancy-flow will not choose for you. '
                .'Set config("fancy-flow.llm.driver") to "prism" or "laravel-ai", '
                .'or register a client explicitly with FancyFlow\\Capabilities\\Capabilities::setLlmClient().';
        }

        return 'llm_router: no LLM client available. Install one of the supported libraries — '
            .'`composer require prism-php/prism` or `composer require laravel/ai` — and fancy-flow wires it automatically. '
            .'To use anything else, implement FancyFlow\\Capabilities\\LlmClient and register it with '
            .'Capabilities::setLlmClient() (or bind it in the Laravel container). '
            .'fancy-flow ships the routing, not the model call.';
    }

    /** @param array<string,mixed> $config */
    private static function driver(array $config): ?string
    {
        $driver = $config['driver'] ?? null;

        return is_string($driver) && $driver !== '' ? $driver : null;
    }

    /** @param array<string,mixed> $config */
    private static function make(string $driver, array $config): ?LlmClient
    {
        $provider = isset($config['provider']) && is_string($config['provider']) ? $config['provider'] : null;
        $model = isset($config['model']) && is_string($config['model']) ? $config['model'] : null;
        $credentials = isset($config['credentials']) && is_callable($config['credentials'])
            ? $config['credentials']
            : null;

        return match ($driver) {
            self::PRISM => new PrismLlmClient($provider, $model, $credentials),
            self::LARAVEL_AI => new LaravelAiLlmClient($provider, $model, $credentials),
            default => null,
        };
    }

    private static function installHint(string $driver): string
    {
        return match ($driver) {
            self::PRISM => 'Run `composer require prism-php/prism`.',
            self::LARAVEL_AI => 'Run `composer require laravel/ai`.',
            default => 'Supported drivers are "prism" and "laravel-ai".',
        };
    }
}
