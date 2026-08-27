<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Runtime\RunEvent;

/**
 * Warn when a routing decision was made on a path that DID NOT RESOLVE.
 *
 * ## The failure this exists for
 *
 * `branch` resolves its `condition` and asks {@see Expr::truthy()}. An
 * unresolvable path yields `null`, `null` is falsy, and the run takes the
 * `false` port — **silently, and for the wrong reason.** Nothing fails, nothing
 * warns, and the flow simply goes the wrong way.
 *
 * `switch_case` has the identical shape one step over: a `value` that does not
 * resolve falls through to `default`.
 *
 * From the outside that is indistinguishable from a condition that was
 * legitimately false. It is the same collapse as an unresolvable path
 * interpolating to `''`, except that here it silently changes the ROUTE rather
 * than the text — so a whole half of the graph never runs and the run reports
 * success.
 *
 * ## Why a warning and not a refusal
 *
 * Routing is deliberately unchanged: an unresolved condition still takes
 * `false`, because changing that would silently re-route graphs that have been
 * running for months. The warning supplies the part that was missing, which is
 * the REASON. A host that would rather fail has `UnresolvedPolicy::Throw` on
 * `Expr::evaluate()`.
 *
 * Found by `flabs`: an agent built a correct triage graph whose urgency check
 * referenced a field that did not resolve, so every request — including one
 * reporting total payment failure — was routed as non-urgent. The graph was
 * right, the path was wrong, and nothing said so.
 */
final class RoutingDiagnostics
{
    /**
     * Emit a `warn` when `$condition` is a single `{{ path }}` resolving to
     * nothing.
     *
     * Only for a WHOLE expression. A condition mixing literal text with
     * expressions is being used as a string, and an unresolved fragment there is
     * the interpolation case rather than a routing one.
     */
    public static function warnIfUnresolved(
        ExecutionContext $ctx,
        mixed $condition,
        string $tookPort,
        string $configKey = 'condition',
    ): void {
        if (! is_string($condition)) {
            return;
        }

        $trimmed = trim($condition);

        if (strlen($trimmed) < 4 || ! str_starts_with($trimmed, '{{') || ! str_ends_with($trimmed, '}}')) {
            return;
        }

        $path = trim(substr($trimmed, 2, -2));

        // Guards the documented `{{a}}{{b}}` corner, where the "path" spans an
        // inner `}}{{`. That is a malformed template rather than a missing
        // field, and reporting it as a missing field sends the reader somewhere
        // useless.
        if ($path === '' || str_contains($path, '}}')) {
            return;
        }

        if (Expr::tryResolvePath($path, $ctx->inputs)->resolved) {
            return;
        }

        $ctx->emit(RunEvent::log(
            'warn',
            sprintf(
                'Node %s took the "%s" port because `%s` resolved to NOTHING — the path %s names no '
                .'field on this node\'s inputs. That is not the same as a false condition: the route '
                .'was decided by an absent value rather than by the data.',
                $ctx->node->id,
                $tookPort,
                $configKey,
                $path,
            ),
            $ctx->node->id,
            ['node' => $ctx->node->id, 'configKey' => $configKey, 'path' => $path, 'tookPort' => $tookPort],
        ));
    }
}
