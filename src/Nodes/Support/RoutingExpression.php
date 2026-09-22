<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

use FancyFlow\Runtime\ExecutionContext;

/** Validate only routing fields; ordinary string configuration stays literal. */
final class RoutingExpression
{
    public static function validate(ExecutionContext $ctx, mixed $value, string $kind, string $field): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $bare = trim($value);
        $subject = $kind.' "'.$ctx->node->id.'" '.$field.' "'.$bare.'"';
        if (! str_contains($value, '{{')) {
            $ctx->abort($subject.' is not an expression -- wrap it: {{ '.$bare.' }}');
        }

        // Count matched delimiters in order, including later interpolations.
        // A completed first template must not conceal an unclosed second one.
        preg_match_all('/\{\{|\}\}/', $value, $matches);
        $open = 0;
        foreach ($matches[0] as $delimiter) {
            $open = $delimiter === '{{' ? $open + 1 : max(0, $open - 1);
        }
        if ($open > 0) {
            $ctx->abort($subject.' has an unclosed expression -- close every {{ with }}');
        }
    }
}
