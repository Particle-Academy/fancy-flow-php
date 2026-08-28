<?php

declare(strict_types=1);

use FancyFlow\Security\RecordedPayload;

it('recursively redacts common sensitive keys without hiding ordinary token metrics', function () {
    $recorded = RecordedPayload::sanitize([
        'password' => 'hunter2',
        'profile' => [
            'apiKey' => 'sk-live',
            'headers' => [
                'Authorization' => 'Bearer secret',
                'X-Request-Id' => 'req-1',
            ],
        ],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
    ]);

    expect($recorded)->toBe([
        'password' => RecordedPayload::REDACTED,
        'profile' => [
            'apiKey' => RecordedPayload::REDACTED,
            'headers' => [
                'Authorization' => RecordedPayload::REDACTED,
                'X-Request-Id' => 'req-1',
            ],
        ],
        'usage' => ['input_tokens' => 12, 'output_tokens' => 7],
    ]);
});

it('bounds the durable payload while retaining a visible truncation marker', function () {
    $recorded = RecordedPayload::sanitize([
        'first' => str_repeat('a', 200),
        'second' => str_repeat('b', 200),
    ], maxBytes: 128);

    $json = json_encode($recorded, JSON_THROW_ON_ERROR);

    expect(strlen($json))->toBeLessThanOrEqual(256);
    expect($json)->toContain(RecordedPayload::TRUNCATED);
});

it('normalizes objects and invalid utf8 into json-safe recorded data', function () {
    $recorded = RecordedPayload::sanitize([
        'object' => (object) ['client_secret' => 'hide', 'name' => "bad\xB1"],
    ]);

    expect($recorded['object']['client_secret'])->toBe(RecordedPayload::REDACTED);
    expect(fn () => json_encode($recorded, JSON_THROW_ON_ERROR))->not->toThrow(JsonException::class);
});
