<?php

declare(strict_types=1);

/**
 * `{{ x.length }}` on a list resolves to the list's size, as it does in the
 * TypeScript engine.
 *
 * Found by lab run 1403 (test-9, batch scoring). The builder wrote an assert of
 * `{{ in.results.length }}` after a `for_each`. The engine delivered `results`
 * correctly, five entries, but PHP's path walk only followed array KEYS, so
 * `length` named nothing and the assert reported a broken path on a correct
 * run. The TypeScript engine resolves the same template to 5, so the same graph
 * passed on one runtime and failed on another.
 */

use FancyFlow\Nodes\Support\Expr;

function lengthContext(): array
{
    return ['in' => [
        'results' => [['a' => 1], ['a' => 2], ['a' => 3]],
        'empty' => [],
        'named' => ['length' => 7, 'other' => 1],
        'text' => 'abc',
        'count' => 3,
    ]];
}

it('resolves .length on a list to its size', function () {
    $r = Expr::tryResolvePath('in.results.length', lengthContext());

    expect($r->resolved)->toBeTrue()
        ->and($r->value)->toBe(3);
});

it('keeps the type in a whole-expression template', function () {
    expect(Expr::evaluate('{{ in.results.length }}', lengthContext()))->toBe(3)
        ->and(Expr::evaluate('{{ $json.results.length }}', lengthContext()))->toBe(3);
});

it('interpolates .length into text', function () {
    expect(Expr::evaluate('scored {{ in.results.length }} rows', lengthContext()))->toBe('scored 3 rows');
});

it('gives 0 for an empty list', function () {
    $r = Expr::tryResolvePath('in.empty.length', lengthContext());

    expect($r->resolved)->toBeTrue()
        ->and($r->value)->toBe(0);
});

it('still reads a real length key on an object', function () {
    expect(Expr::evaluate('{{ in.named.length }}', lengthContext()))->toBe(7);
});

it('does not invent length on a map without that key, or on a string', function () {
    expect(Expr::tryResolvePath('in.named.size', lengthContext())->resolved)->toBeFalse()
        ->and(Expr::tryResolvePath('in.text.length', lengthContext())->resolved)->toBeFalse()
        ->and(Expr::tryResolvePath('in.count.length', lengthContext())->resolved)->toBeFalse();
});

it('does not walk past length', function () {
    expect(Expr::tryResolvePath('in.results.length.more', lengthContext())->resolved)->toBeFalse();
});
