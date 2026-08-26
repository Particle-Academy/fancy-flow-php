<?php

declare(strict_types=1);

use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\UnresolvedPathException;
use FancyFlow\Nodes\Support\UnresolvedPolicy;

/**
 * "Did not resolve" must be distinguishable from "resolved to empty".
 *
 * `Expr::resolvePath()` returns `null` both for a path that does not exist and
 * for a path that exists holding `null`. At the interpolation layer that
 * collapse gets worse, because `null` stringifies to `''`. The consumer who
 * reported it put it exactly:
 *
 * > "An unresolvable path yields `''`, so a wrong field is indistinguishable
 * > from an empty one at runtime."
 *
 * A misspelled field renders as an empty string, which looks precisely like a
 * field that is legitimately empty. The graph runs, the node succeeds, and the
 * output is quietly missing a value nobody is told about.
 *
 * These cases mirror `tests/unresolved-path.test.ts` in `fancy-flow` one for
 * one, so the two runtimes can be read side by side.
 */
function ctx(): array
{
    return [
        'in' => ['text' => 'hello', 'empty' => '', 'nothing' => null, 'count' => 0],
        'n1' => ['nested' => ['deep' => 'found']],
    ];
}

it('reports a missing path as unresolved', function () {
    $r = Expr::tryResolvePath('in.missing', ctx());

    expect($r->resolved)->toBeFalse();
    expect($r->value)->toBeNull();
});

it('reports a path holding NULL as RESOLVED', function () {
    // The whole point. resolvePath() cannot tell these two apart.
    $r = Expr::tryResolvePath('in.nothing', ctx());

    expect($r->resolved)->toBeTrue();
    expect($r->value)->toBeNull();
    expect(Expr::resolvePath('in.nothing', ctx()))->toBe(Expr::resolvePath('in.missing', ctx()));
});

it('reports empty-string and zero as resolved', function () {
    // Falsy but present. `resolved` must never be computed from truthiness.
    expect(Expr::tryResolvePath('in.empty', ctx())->resolved)->toBeTrue();
    expect(Expr::tryResolvePath('in.empty', ctx())->value)->toBe('');
    expect(Expr::tryResolvePath('in.count', ctx())->resolved)->toBeTrue();
    expect(Expr::tryResolvePath('in.count', ctx())->value)->toBe(0);
});

it('treats walking into a scalar or a null as unresolved', function () {
    expect(Expr::tryResolvePath('in.text.nope', ctx())->resolved)->toBeFalse();
    expect(Expr::tryResolvePath('in.nothing.nope', ctx())->resolved)->toBeFalse();
});

it('resolves through nesting and the $json alias', function () {
    expect(Expr::tryResolvePath('n1.nested.deep', ctx())->value)->toBe('found');
    expect(Expr::tryResolvePath('$json.text', ctx())->value)->toBe('hello');
    expect(Expr::tryResolvePath('$input.text', ctx())->value)->toBe('hello');
});

it('treats an empty path as unresolved', function () {
    expect(Expr::tryResolvePath('   ', ctx())->resolved)->toBeFalse();
});

it('sees a declared object property holding NULL as resolved', function () {
    // The PHP-only half of the same collapse, and the reason this is not a
    // straight port of the TS walk: the object branch tested `isset()`, which
    // is false for a property that exists and holds null -- `isset()` IS the
    // absent-vs-null collapse. Relying on it alone would have reproduced the
    // bug inside the fix.
    $obj = new class
    {
        public ?string $name = null;

        public string $label = 'x';
    };

    expect(Expr::tryResolvePath('o.name', ['o' => $obj])->resolved)->toBeTrue();
    expect(Expr::tryResolvePath('o.name', ['o' => $obj])->value)->toBeNull();
    expect(Expr::tryResolvePath('o.label', ['o' => $obj])->value)->toBe('x');
    expect(Expr::tryResolvePath('o.nope', ['o' => $obj])->resolved)->toBeFalse();
});

it('still honours magic __isset/__get, which property_exists cannot see', function () {
    // Why isset() is tested FIRST and property_exists only as a fallback. An
    // Eloquent model's attributes are magic: property_exists is false for every
    // one of them. Testing property_exists first would break every host that
    // puts a model in the context.
    $magic = new class
    {
        private array $attrs = ['email' => 'a@b.c'];

        public function __isset(string $k): bool
        {
            return isset($this->attrs[$k]);
        }

        public function __get(string $k): mixed
        {
            return $this->attrs[$k] ?? null;
        }
    };

    expect(Expr::tryResolvePath('m.email', ['m' => $magic])->value)->toBe('a@b.c');
    expect(Expr::tryResolvePath('m.nope', ['m' => $magic])->resolved)->toBeFalse();
});

it('resolvePath is unchanged by the delegation', function () {
    // It is now DEFINED in terms of tryResolvePath, so this pins that not one
    // answer moved.
    expect(Expr::resolvePath('in.missing', ctx()))->toBeNull();
    expect(Expr::resolvePath('in.nothing', ctx()))->toBeNull();
    expect(Expr::resolvePath('in.text', ctx()))->toBe('hello');
    expect(Expr::resolvePath('in.count', ctx()))->toBe(0);
    expect(Expr::resolvePath('$json.text', ctx()))->toBe('hello');
});

it('defaults to the existing behaviour with no policy passed', function () {
    expect(Expr::evaluate('Hi {{ in.missing }}!', ctx()))->toBe('Hi !');
    expect(Expr::evaluate('{{ in.missing }}', ctx()))->toBeNull();
    expect(Expr::evaluate('{{ in.missing }}', ctx()))
        ->toBe(Expr::evaluate('{{ in.missing }}', ctx(), UnresolvedPolicy::Empty));
});

it('keeps the template text under the Keep policy', function () {
    expect(Expr::evaluate('Hi {{ in.missing }}!', ctx(), UnresolvedPolicy::Keep))
        ->toBe('Hi {{ in.missing }}!');

    // Byte-identical round trip, spacing included -- `$m[0]` is the whole match.
    $t = 'a {{in.missing}} b {{   in.missing   }} c';
    expect(Expr::evaluate($t, ctx(), UnresolvedPolicy::Keep))->toBe($t);

    expect(Expr::evaluate('x {{ in.text }} / {{ in.missing }}', ctx(), UnresolvedPolicy::Keep))
        ->toBe('x hello / {{ in.missing }}');
});

it('does NOT keep a path that resolved to null or empty', function () {
    // The distinction the whole change exists for: resolved-but-empty
    // interpolates to nothing under EVERY policy. Only UNRESOLVED is special.
    expect(Expr::evaluate('[{{ in.nothing }}]', ctx(), UnresolvedPolicy::Keep))->toBe('[]');
    expect(Expr::evaluate('[{{ in.empty }}]', ctx(), UnresolvedPolicy::Keep))->toBe('[]');
});

it('throws under the Throw policy, naming the path', function () {
    expect(fn () => Expr::evaluate('Hi {{ in.missing }}', ctx(), UnresolvedPolicy::Throw))
        ->toThrow(UnresolvedPathException::class);

    try {
        Expr::evaluate('{{ in.missing }}', ctx(), UnresolvedPolicy::Throw);
        $this->fail('should have thrown');
    } catch (UnresolvedPathException $e) {
        expect(trim($e->path))->toBe('in.missing');
        expect($e->getMessage())->toContain('in.missing');
    }
});

it('does NOT throw for a path that resolved to null or empty', function () {
    expect(Expr::evaluate('[{{ in.nothing }}]', ctx(), UnresolvedPolicy::Throw))->toBe('[]');
    expect(Expr::evaluate('[{{ in.empty }}]', ctx(), UnresolvedPolicy::Throw))->toBe('[]');
    expect(Expr::evaluate('{{ in.count }}', ctx(), UnresolvedPolicy::Throw))->toBe(0);
});

it('makes the documented {{a}}{{b}} corner visible rather than silently null', function () {
    // A template that both starts with `{{` and ends with `}}` is ONE whole
    // expression whose path contains the inner `}}{{`. Deliberate, and mirrored
    // in the TS scanner. Under Keep the author at least SEES the template was
    // never split.
    $twoLooking = '{{ in.text }} / {{ in.text }}';

    expect(Expr::evaluate($twoLooking, ctx()))->toBeNull();
    expect(Expr::evaluate($twoLooking, ctx(), UnresolvedPolicy::Keep))->toBe($twoLooking);
});
