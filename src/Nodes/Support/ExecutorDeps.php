<?php

declare(strict_types=1);

namespace FancyFlow\Nodes\Support;

/**
 * The injectable clients the built-in executors depend on. Every field defaults
 * to a deterministic framework-free implementation, so `new ExecutorDeps()`
 * yields a fully-working (offline) executor set. Pass real clients to wire the
 * built-ins to the outside world; the 0.2 Laravel layer builds this from the
 * container (HTTP client, laravel/ai, cache/Eloquent, Notifications).
 */
final class ExecutorDeps
{
    public function __construct(
        public readonly HttpClient $http = new EchoHttpClient(),
        public readonly LlmClient $llm = new EchoLlmClient(),
        public readonly ToolInvoker $tools = new EchoToolInvoker(),
        public readonly VectorStore $vectors = new EmptyVectorStore(),
        public readonly Notifier $notifier = new RecordingNotifier(),
        public readonly KeyValueStore $memory = new ArrayStore(),
        public readonly KeyValueStore $data = new ArrayStore(),
    ) {}
}
