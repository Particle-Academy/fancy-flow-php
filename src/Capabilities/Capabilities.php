<?php

declare(strict_types=1);

namespace FancyFlow\Capabilities;

/**
 * Host capabilities — the services core nodes need but must never depend on.
 *
 * A node that imports a provider SDK forces every consumer to install it: a
 * workflow app that never calls a model should not inherit an LLM dependency.
 * So core declares the CONTRACT and the host supplies the implementation.
 *
 * The PHP twin of fancy-flow's `capabilities.ts` module-globals. Two ways in:
 *
 *   - {@see setLlmClient()} / {@see setWorkflowResolver()} — plain static
 *     setters, so the framework-free core stays usable with no container.
 *   - the Laravel layer binds {@see LlmClient} / {@see WorkflowResolver} in the
 *     container; the service provider forwards those here.
 *
 * When nothing is registered, {@see llmClient()} AUTO-DETECTS a supported
 * library (Prism, laravel/ai). A client you register yourself always wins —
 * hand-rolled stays first-class.
 */
final class Capabilities
{
    private static ?LlmClient $llmClient = null;

    /** Set once auto-detection has run, so a null result isn't retried per node. */
    private static bool $llmDetected = false;

    private static ?LlmClient $detectedLlmClient = null;

    /** @var array<string,mixed> Host config for auto-detection (driver, provider, model, …). */
    private static array $llmConfig = [];

    private static ?WorkflowResolver $workflowResolver = null;

    // ── LLM ─────────────────────────────────────────────────────────────────

    /**
     * Install the host's LLM client. Returns an unregister callable.
     *
     * Passing an explicit client REPLACES anything auto-detected — registering
     * your own is how you opt out of the shipped adapters entirely.
     *
     * @return callable():void
     */
    public static function setLlmClient(?LlmClient $client): callable
    {
        self::$llmClient = $client;

        return static function () use ($client): void {
            if (self::$llmClient === $client) {
                self::$llmClient = null;
            }
        };
    }

    /**
     * Configure auto-detection: `driver` (`prism` | `laravel-ai`), plus the
     * `provider` / `model` defaults handed to whichever adapter is chosen.
     *
     * @param array<string,mixed> $config
     */
    public static function configureLlm(array $config): void
    {
        self::$llmConfig = $config;
        // A config change can change the answer, so the cached detection goes.
        self::$llmDetected = false;
        self::$detectedLlmClient = null;
    }

    /** @return array<string,mixed> */
    public static function llmConfig(): array
    {
        return self::$llmConfig;
    }

    /**
     * The LLM client for `llm_router`: the registered one, else an
     * auto-detected adapter, else null.
     *
     * Null is never "guess a branch" — {@see llmUnavailableMessage()} explains
     * what to install or register and the node aborts with it.
     */
    public static function llmClient(): ?LlmClient
    {
        if (self::$llmClient !== null) {
            return self::$llmClient;
        }

        if (! self::$llmDetected) {
            self::$detectedLlmClient = LlmClientDetector::detect(self::$llmConfig);
            self::$llmDetected = true;
        }

        return self::$detectedLlmClient;
    }

    /** Why no client is available, phrased as what to do about it. */
    public static function llmUnavailableMessage(): string
    {
        return LlmClientDetector::unavailableMessage(self::$llmConfig);
    }

    // ── Workflow resolution ─────────────────────────────────────────────────

    /**
     * Install the host's workflow resolver. Returns an unregister callable.
     *
     * @return callable():void
     */
    public static function setWorkflowResolver(?WorkflowResolver $resolver): callable
    {
        self::$workflowResolver = $resolver;

        return static function () use ($resolver): void {
            if (self::$workflowResolver === $resolver) {
                self::$workflowResolver = null;
            }
        };
    }

    public static function workflowResolver(): ?WorkflowResolver
    {
        return self::$workflowResolver;
    }

    // ── Introspection ───────────────────────────────────────────────────────

    /**
     * Which capabilities are currently satisfied.
     *
     * Exists so a host (or an agent over MCP) can answer "what does this graph
     * need that I haven't wired?" BEFORE a run fails halfway through.
     *
     * @return array{llm:bool,workflow_resolver:bool}
     */
    public static function status(): array
    {
        return [
            'llm' => self::llmClient() !== null,
            'workflow_resolver' => self::$workflowResolver !== null,
        ];
    }

    /** Clear everything. Test isolation — the analogue of NodeKindRegistry::resetDefault(). */
    public static function reset(): void
    {
        self::$llmClient = null;
        self::$llmDetected = false;
        self::$detectedLlmClient = null;
        self::$llmConfig = [];
        self::$workflowResolver = null;
    }
}
