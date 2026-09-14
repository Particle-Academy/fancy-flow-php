<?php

declare(strict_types=1);

use FancyFlow\Nodes\Support\Expr;
use FancyFlow\Nodes\Support\UnresolvedPathException;
use FancyFlow\Nodes\Support\UnresolvedPolicy;

/*
 * fancy-flow-php#16: a template that starts with `{{` and ends with `}}` but
 * holds more than one reference.
 *
 * The whole-string fast path (`/^\{\{\s*(.*?)\s*\}\}$/s`) is end-anchored, so its
 * lazy capture grew to the end: `{{ in.text }} --- {{ user.transcript }}` was
 * ONE path, `in.text }} --- {{ user.transcript`, which resolves to nothing. The
 * template returned null under Empty — a document node wrote nothing, and a
 * consumer spent four reports chasing edges and ports before finding it.
 *
 * It was documented as a deliberate corner and mirrored in every runtime, which
 * is why no parity table could catch it. The whole-string branch now applies
 * only to exactly ONE expression; anything else interpolates.
 */

function multiRefContext(): array
{
    return ['in' => ['text' => 'SUMMARY'], 'user' => ['transcript' => 'TRANSCRIPT', 'title' => 'Call'], 'a' => 1, 'b' => 2];
}

dataset('policies', [
    'Empty' => [UnresolvedPolicy::Empty],
    'Keep' => [UnresolvedPolicy::Keep],
    'Throw' => [UnresolvedPolicy::Throw],
]);

it('interpolates every reference of a template that starts and ends with one', function (UnresolvedPolicy $policy) {
    $ctx = multiRefContext();

    expect(Expr::evaluate("{{ in.text }}\n\n---\n\n## Original\n\n{{ user.transcript }}", $ctx, $policy))
        ->toBe("SUMMARY\n\n---\n\n## Original\n\nTRANSCRIPT");
    expect(Expr::evaluate('{{ user.title }} - {{ in.text }}', $ctx, $policy))->toBe('Call - SUMMARY');
    expect(Expr::evaluate('Summary: {{ in.text }} --- {{ user.transcript }}', $ctx, $policy))->toBe('Summary: SUMMARY --- TRANSCRIPT');
    // Adjacent references are two references, not one path spanning `}}{{`.
    expect(Expr::evaluate('{{ a }}{{ b }}', $ctx, $policy))->toBe('12');
})->with('policies');

it('still returns the typed value for exactly one expression', function (UnresolvedPolicy $policy) {
    expect(Expr::evaluate('{{ in.text }}', multiRefContext(), $policy))->toBe('SUMMARY');
    expect(Expr::evaluate(' {{ a }} ', multiRefContext(), $policy))->toBe(1);
})->with('policies');

it('applies the policy per reference when one of several does not resolve', function () {
    $ctx = multiRefContext();
    $template = '{{ in.text }} / {{ in.nope }}';

    expect(Expr::evaluate($template, $ctx))->toBe('SUMMARY / ');
    expect(Expr::evaluate($template, $ctx, UnresolvedPolicy::Keep))->toBe('SUMMARY / {{ in.nope }}');
    expect(fn () => Expr::evaluate($template, $ctx, UnresolvedPolicy::Throw))->toThrow(UnresolvedPathException::class);
});
