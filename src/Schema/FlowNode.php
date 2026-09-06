<?php

declare(strict_types=1);

namespace FancyFlow\Schema;

/**
 * A runtime node in a {@see FlowGraph}. The PHP twin of fancy-flow's
 * `FlowNode` (an xyflow node with a `data` slot), flattened for ergonomics.
 *
 * `$type` is the registry kind (e.g. "memory_store") — the same value the
 * TS side stores as both the xyflow node `type` and `data.kind`.
 *
 * `$inputs` / `$outputs` are intentionally nullable: `null` means "ports not
 * declared" (the engine falls back to a single `out` port), whereas an empty
 * array means "explicitly no ports" (a terminal node). {@see \FancyFlow\Engine\FlowRunner}
 * relies on that distinction to match the TS engine byte-for-byte.
 */
final class FlowNode
{
    /**
     * @param array<string,mixed>       $config
     * @param list<PortDescriptor>|null $inputs
     * @param list<PortDescriptor>|null $outputs
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $type = null,
        public readonly float $x = 0.0,
        public readonly float $y = 0.0,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        /**
         * Announced to a person just BEFORE this node runs -- "Starting the
         * deep analysis". Optional on purpose: most nodes in a graph are
         * plumbing, and a run that narrates all of them buries the two or
         * three steps anyone follows.
         */
        public readonly ?string $startingMsg = null,
        /**
         * Announced AFTER this node finishes -- "Analysis complete". Emitted
         * only when the node SUCCEEDS: a completion message printed after a
         * failure tells a human the opposite of what happened.
         */
        public readonly ?string $stoppingMsg = null,
        public readonly array $config = [],
        public readonly ?array $inputs = null,
        public readonly ?array $outputs = null,
        /**
         * The node this one sits INSIDE -- a swimlane or other container.
         *
         * Not decoration. The WorkflowSchema comment used to say these visual
         * fields exist "purely for the canvas", so a runtime that only walks
         * edges and ports could ignore them -- and this runtime did:
         * `parentId` was neither imported nor exported, so a graph read here
         * and written back lost every grouping a person had drawn, silently
         * and completely. A tool that round-trips a workflow is the normal
         * case, not an exotic one.
         *
         * The TypeScript runtime now makes it load-bearing for EXECUTION too:
         * a terminal lane owns one session and membership is this field.
         */
        public readonly ?string $parentId = null,
        /**
         * `"parent"`, or `[[x1, y1], [x2, y2]]` bounds. Carried WITH
         * `parentId` rather than separately: a container whose child kept its
         * parent but lost its containment rule is a half-restored graph, which
         * is harder to notice than one that plainly lost the grouping.
         */
        public readonly mixed $extent = null,
        /** An explicit (resized) size, and inline presentation. Round-tripped
         *  for the same reason: a tool that reads a graph here and writes it
         *  back must not flatten a canvas somebody laid out. */
        public readonly ?float $width = null,
        public readonly ?float $height = null,
        public readonly ?array $style = null,
    ) {}

    /** The registry kind name — alias for {@see $type}. */
    public function kind(): ?string
    {
        return $this->type;
    }

    /** Read a single config value with a dot-free key. */
    public function configValue(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }
}
