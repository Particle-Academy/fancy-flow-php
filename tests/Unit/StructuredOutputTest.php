<?php

declare(strict_types=1);

use FancyFlow\Exceptions\FlowException;
use FancyFlow\Nodes\Support\StructuredOutput;

/**
 * Schema-typed output for `llm_call` (fancy-flow#6).
 *
 * The cases below are the ones the report described from real runs — fences,
 * preambles, truncation — plus the one that matters most: a result that does
 * not match the schema must RAISE, never flow onward as null. A truncated array
 * that decodes to nothing is indistinguishable from a model that found no
 * results, and a workflow that quietly processes zero records is the expensive
 * kind of wrong.
 */
describe('extract', function () {
    it('reads a bare JSON value', function () {
        expect(StructuredOutput::extract('{"title":"a"}'))->toBe(['title' => 'a']);
        expect(StructuredOutput::extract('[1, 2, 3]'))->toBe([1, 2, 3]);
    });

    it('strips a ```json fence', function () {
        $text = "```json\n{\"title\":\"a\"}\n```";

        expect(StructuredOutput::extract($text))->toBe(['title' => 'a']);
    });

    it('strips a bare ``` fence', function () {
        expect(StructuredOutput::extract("```\n[1,2]\n```"))->toBe([1, 2]);
    });

    it('ignores a prose preamble and a trailing note', function () {
        $text = "Here are the results:\n[{\"id\":1}]\n\nLet me know if you need more.";

        expect(StructuredOutput::extract($text))->toBe([['id' => 1]]);
    });

    it('does not mistake a brace inside a string for structure', function () {
        // The reason this scans instead of counting characters naively.
        $text = 'Note: {"label":"a } b","n":1}';

        expect(StructuredOutput::extract($text))->toBe(['label' => 'a } b', 'n' => 1]);
    });

    it('RAISES on truncation rather than returning nothing', function () {
        // The failure the whole feature exists for: this used to decode to null
        // downstream and read as "no results".
        $truncated = '[{"id":1},{"id":2},{"ti';

        expect(fn () => StructuredOutput::extract($truncated))->toThrow(FlowException::class);
    });

    it('raises on an empty response', function () {
        expect(fn () => StructuredOutput::extract('   '))->toThrow(FlowException::class);
    });

    it('raises when there is no JSON at all', function () {
        expect(fn () => StructuredOutput::extract('I am unable to help with that.'))
            ->toThrow(FlowException::class);
    });
});

describe('validate', function () {
    $schema = [
        'type' => 'array',
        'items' => [
            'type' => 'object',
            'required' => ['title', 'score'],
            'properties' => [
                'title' => ['type' => 'string'],
                'score' => ['type' => 'number'],
                'tag' => ['type' => 'string', 'enum' => ['a', 'b']],
            ],
        ],
    ];

    it('passes a conforming value', function () use ($schema) {
        $value = [['title' => 'x', 'score' => 1.5, 'tag' => 'a']];

        expect(StructuredOutput::validate($value, $schema))->toBe([]);
    });

    it('names a missing required key, with its path', function () use ($schema) {
        $errors = StructuredOutput::validate([['title' => 'x']], $schema);

        expect($errors)->toHaveCount(1);
        expect($errors[0])->toContain('score');
        // The path matters: "score is required" is useless in a 40-row array.
        expect($errors[0])->toContain('[0]');
    });

    it('catches a wrong type', function () use ($schema) {
        $errors = StructuredOutput::validate([['title' => 5, 'score' => 1]], $schema);

        expect($errors)->not->toBeEmpty();
        expect($errors[0])->toContain('string');
    });

    it('accepts an int where the schema says number', function () {
        // JSON has one number type. Rejecting `3` for `{"type":"number"}` would
        // fail on the most ordinary schema anyone writes.
        expect(StructuredOutput::validate(3, ['type' => 'number']))->toBe([]);
        expect(StructuredOutput::validate(3.5, ['type' => 'number']))->toBe([]);
        expect(StructuredOutput::validate(3.5, ['type' => 'integer']))->not->toBe([]);
    });

    it('distinguishes an object from an array', function () {
        expect(StructuredOutput::validate([1, 2], ['type' => 'object']))->not->toBe([]);
        expect(StructuredOutput::validate(['a' => 1], ['type' => 'array']))->not->toBe([]);
        // An empty array is a valid JSON array, and a valid empty object too --
        // it must not fail either way just because PHP cannot tell them apart.
        expect(StructuredOutput::validate([], ['type' => 'array']))->toBe([]);
        expect(StructuredOutput::validate([], ['type' => 'object']))->toBe([]);
    });

    it('enforces enum', function () use ($schema) {
        $errors = StructuredOutput::validate([['title' => 'x', 'score' => 1, 'tag' => 'z']], $schema);

        expect($errors)->not->toBeEmpty();
    });

    it('ignores keywords outside the supported subset instead of pretending', function () {
        // Documented behaviour, asserted so nobody assumes full JSON Schema:
        // `minLength` is NOT enforced. If that changes, this test should be the
        // thing that makes someone update the docblock too.
        expect(StructuredOutput::validate('', ['type' => 'string', 'minLength' => 5]))->toBe([]);
    });
});
