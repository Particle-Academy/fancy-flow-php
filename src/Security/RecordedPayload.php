<?php

declare(strict_types=1);

namespace FancyFlow\Security;

/**
 * Makes live execution data safe and finite before it becomes durable history.
 *
 * The executor still receives the original value. Only the inspectable copy is
 * normalized, recursively redacted by key, and bounded for storage.
 */
final class RecordedPayload
{
    public const REDACTED = '[REDACTED]';

    public const TRUNCATED = '[TRUNCATED]';

    private const MAX_DEPTH = 20;

    private const MAX_ITEMS = 1000;

    private const MAX_STRING_BYTES = 16_384;

    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'api_key', 'apikey', 'auth', 'authorization', 'client_secret',
        'cookie', 'credential', 'credentials', 'password', 'passwd',
        'passphrase', 'private_key', 'refresh_token', 'access_token',
        'secret', 'set_cookie', 'token',
    ];

    /**
     * @param array<string|int,mixed> $value
     * @return array<string|int,mixed>
     */
    public static function sanitize(array $value, int $maxBytes = 262_144): array
    {
        $maxBytes = max(64, $maxBytes);
        $normalized = self::normalize($value);
        $budget = max(1, $maxBytes - 32);
        $recorded = self::walk(is_array($normalized) ? $normalized : [], $budget, 0);

        if (! is_array($recorded)) {
            return ['__fancy_flow_truncated__' => self::TRUNCATED];
        }

        $json = json_encode($recorded, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        if ($json !== false && strlen($json) <= $maxBytes) {
            return $recorded;
        }

        // The walker budgets values conservatively, but JSON punctuation and
        // unusually long keys can still cross the hard ceiling. Never let an
        // accounting approximation turn the configured limit into a suggestion.
        return ['__fancy_flow_truncated__' => self::TRUNCATED];
    }

    private static function normalize(mixed $value): mixed
    {
        $json = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? null : json_decode($json, true);
    }

    private static function walk(mixed $value, int &$budget, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            return self::marker($budget);
        }

        if (is_array($value)) {
            $out = [];
            $count = 0;
            $list = array_is_list($value);

            foreach ($value as $key => $item) {
                if ($count++ >= self::MAX_ITEMS || $budget <= 32) {
                    self::appendMarker($out, $list);
                    break;
                }

                $budget -= min($budget, strlen((string) $key) + 8);
                $out[$key] = self::isSensitiveKey((string) $key)
                    ? self::consume(self::REDACTED, $budget)
                    : self::walk($item, $budget, $depth + 1);
            }

            return $out;
        }

        if (is_string($value)) {
            $available = max(0, min(self::MAX_STRING_BYTES, $budget - strlen(self::TRUNCATED) - 4));
            if (strlen($value) <= $available) {
                return self::consume($value, $budget);
            }

            $prefix = self::validUtf8Prefix($value, $available);

            return self::consume($prefix.self::TRUNCATED, $budget);
        }

        $encoded = json_encode($value, JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        $budget -= min($budget, strlen($encoded === false ? 'null' : $encoded) + 2);

        return $value;
    }

    private static function isSensitiveKey(string $key): bool
    {
        $snake = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        $normalized = strtolower((string) preg_replace('/[^a-zA-Z0-9]+/', '_', $snake));
        $normalized = trim($normalized, '_');

        if (in_array($normalized, self::SENSITIVE_KEYS, true)) {
            return true;
        }

        foreach (['_password', '_passwd', '_passphrase', '_secret', '_access_token', '_refresh_token', '_api_key', '_private_key', '_client_secret', '_credential', '_credentials'] as $suffix) {
            if (str_ends_with($normalized, $suffix)) {
                return true;
            }
        }

        return false;
    }

    private static function consume(string $value, int &$budget): string
    {
        $budget -= min($budget, strlen($value) + 4);

        return $value;
    }

    private static function marker(int &$budget): string
    {
        return self::consume(self::TRUNCATED, $budget);
    }

    /** @param array<string|int,mixed> $out */
    private static function appendMarker(array &$out, bool $list): void
    {
        if ($list) {
            $out[] = self::TRUNCATED;

            return;
        }

        $out['__fancy_flow_truncated__'] = self::TRUNCATED;
    }

    private static function validUtf8Prefix(string $value, int $bytes): string
    {
        $prefix = substr($value, 0, max(0, $bytes));
        while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
            $prefix = substr($prefix, 0, -1);
        }

        return $prefix;
    }
}
