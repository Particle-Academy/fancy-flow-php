<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * The one piece of prompt shaping the shipped adapters share.
 *
 * Kept OUT of `llm_router` on purpose: the node is a shuttle and owns no
 * prompt engineering. It lives here, next to the adapters, so an adapter is a
 * few lines and a hand-rolled client can reuse it — or ignore it entirely.
 */
final class RoutePrompt
{
    public const DEFAULT_SYSTEM = 'You are a router in a workflow engine. Read the input and choose exactly one '
        .'of the offered routes — the single best match. Answer only with the structured choice.';

    /** The system framing: the node's own, or a sane default. */
    public static function system(LlmRouteRequest $request): string
    {
        $system = $request->system !== null ? trim($request->system) : '';

        return $system !== '' ? $system : self::DEFAULT_SYSTEM;
    }

    /**
     * The user message: what is being routed, then the menu.
     *
     * The route descriptions are what the model actually discriminates on, so
     * they are rendered verbatim and labelled with the port they select.
     */
    public static function user(LlmRouteRequest $request): string
    {
        $lines = ["Input to route:\n".$request->prompt, '', 'Routes:'];
        foreach ($request->routes as $route) {
            $lines[] = $route->description !== null && $route->description !== ''
                ? "- {$route->port}: {$route->description}"
                : "- {$route->port}";
        }
        $lines[] = '';
        $lines[] = 'Choose exactly one route by its exact name from the list above.';

        return implode("\n", $lines);
    }

    /** Description for the enum/port field of the structured schema. */
    public static function portDescription(LlmRouteRequest $request): string
    {
        return 'The chosen route. Must be exactly one of: '.implode(', ', $request->ports()).'.';
    }

    public const REASON_DESCRIPTION = 'One short sentence explaining why this route was chosen.';
}
