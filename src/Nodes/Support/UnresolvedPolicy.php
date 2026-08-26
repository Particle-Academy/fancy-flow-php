<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * What expression evaluation does with a path that does not resolve.
 *
 * The problem this exists for, in the reporting consumer's words:
 *
 * > "An unresolvable path yields `''`, so a wrong field is indistinguishable
 * > from an empty one at runtime."
 *
 * A misspelled field interpolates to an empty string, which looks precisely
 * like a field that is legitimately empty. The graph runs, the node succeeds,
 * and the output is quietly missing a value nobody is told about — worst on
 * LLM-authored graphs, where the field name was guessed to begin with.
 *
 * Same shape as the four `??` collapses fixed across all four runtimes on
 * 2026-08-26 (absent vs null), one layer up.
 */
enum UnresolvedPolicy: string
{
    /**
     * Today's behaviour, and the DEFAULT.
     *
     * Interpolates to `''`; a whole expression yields `null`. Unchanged so that
     * adding this API breaks nobody — 40 call sites across the runtimes assume
     * it. Opt-in before default was the reporting consumer's own condition.
     */
    case Empty = 'empty';

    /**
     * Leave the `{{ … }}` text in place.
     *
     * The failure becomes VISIBLE in the output without stopping the run, which
     * is what you want for human-reviewed content: a rendered
     * `{{ in.recipient_naem }}` is self-diagnosing in a way an absence never is.
     */
    case Keep = 'keep';

    /**
     * Refuse — throw {@see UnresolvedPathException}.
     *
     * For hosts that would rather fail a run than deliver a silently incomplete
     * result.
     */
    case Throw = 'throw';
}
