<?php

declare(strict_types=1);

namespace FancyFlow\Security;

use Closure;
use FancyFlow\Registry\Builtin;
use FancyFlow\Registry\KindId;
use FancyFlow\Schema\ImportIssue;

/**
 * What a graph must satisfy before an untrusted author's copy of it is allowed
 * near a queue.
 *
 * `Workflow::import()` already answers "is this graph COHERENT?" — unknown
 * kinds, dangling edges, missing required config. This answers a different
 * question: **"is it safe to accept and persist?"** A graph that arrives over
 * HTTP from someone you have never met is a payload first and a workflow
 * second, and it gets written to a queue table and rehydrated later by a worker
 * that trusts it.
 *
 * The checks, and what each is actually for:
 *
 *  - **Kind policy.** An allowlist is the load-bearing control: it decides
 *    which executors a stranger may cause to run. Everything else is depth in
 *    front of it.
 *  - **Size caps.** Nodes, edges, nesting depth, string length, total bytes. A
 *    deeply nested config is a stack overflow in whatever parses it next, and
 *    an enormous one is a queue row nobody can process.
 *  - **Byte hygiene.** Invalid UTF-8, NUL, and C0/C1 control characters are
 *    rejected in every string. These do not occur in a real workflow and are
 *    exactly what is used to smuggle content past a log, a terminal, or a
 *    downstream parser that disagrees with PHP about where a string ends.
 *  - **Structure.** Duplicate node ids and edges pointing at nodes that do not
 *    exist — cheap to check, and a duplicate id makes every id-keyed decision
 *    downstream ambiguous.
 *  - **Custom rules.** A host knows things this package cannot: which
 *    credentials exist, what its own marketplace nodes may do. `addRule()`
 *    takes those without anyone patching this class.
 *
 * ## The kind policy is ALIAS-AWARE, and that is the whole point
 *
 * A kind answers to several ids — `user_input`, `@particle-academy/user_input`,
 * `@fancy/user_input`. A denylist keyed on the literal string you happened to
 * write is not a denylist: it is a suggestion that the attacker declines by
 * spelling the kind differently.
 *
 * This is the same defect that let a human gate be walked past (#4), except
 * there it merely failed silently and here it would be a bypass. Every id a
 * kind answers to is resolved before any comparison.
 */
final class GraphPolicy
{
    /** @var list<string>|null Bare kind names; null means "no allowlist". */
    private ?array $allowed = null;

    /** @var list<string> Bare kind names. */
    private array $denied = [];

    /** @var list<Closure(array<string,mixed>): list<ImportIssue>> */
    private array $rules = [];

    private function __construct(
        private int $maxNodes = 60,
        private int $maxEdges = 120,
        private int $maxDepth = 12,
        private int $maxStringLength = 20_000,
        private int $maxBytes = 256_000,
    ) {}

    /**
     * The posture for a graph you did not write.
     *
     * Deliberately strict, and deliberately an ALLOWLIST: a denylist of
     * dangerous kinds is a list you have to keep complete forever, and the
     * first kind added to the package after you wrote it is permitted by
     * default. An allowlist fails the other way, which is the correct way.
     *
     * The caller names what it wants to permit, because only the caller knows.
     * `Builtin` cannot guess which of its own kinds are safe in someone else's
     * app — so the list is a REQUIRED argument rather than something you are
     * trusted to remember to chain.
     *
     * It used to default to an *absent* list, and absent meant "permit
     * everything": a caller who forgot `allowKinds()` got a policy named
     * `untrusted` that restricted no kind at all, silently, while reading as
     * though it were locked down. That is the failure this method exists to
     * prevent, so it can no longer be expressed.
     *
     * @param  list<string>  $allowKinds  Every kind the graph may use.
     */
    public static function untrusted(array $allowKinds): self
    {
        return (new self())->allowKinds($allowKinds);
    }

    /** Caps only, no kind policy — for graphs your own code produced. */
    public static function trusted(): self
    {
        return new self(maxNodes: 5_000, maxEdges: 10_000, maxDepth: 32, maxStringLength: 1_000_000, maxBytes: 8_000_000);
    }

    /**
     * Permit ONLY these kinds. Accepts any spelling; every id each kind answers
     * to is permitted with it.
     *
     * @param  list<string>  $kinds
     */
    public function allowKinds(array $kinds): self
    {
        $clone = clone $this;
        $clone->allowed = array_values(array_unique(array_map(KindId::bare(...), $kinds)));

        return $clone;
    }

    /**
     * Refuse these kinds. Applied after the allowlist, so a kind named in both
     * is refused — the safer reading of a contradiction.
     *
     * @param  list<string>  $kinds
     */
    public function denyKinds(array $kinds): self
    {
        $clone = clone $this;
        $clone->denied = array_values(array_unique([
            ...$clone->denied,
            ...array_map(KindId::bare(...), $kinds),
        ]));

        return $clone;
    }

    public function withLimits(
        ?int $maxNodes = null,
        ?int $maxEdges = null,
        ?int $maxDepth = null,
        ?int $maxStringLength = null,
        ?int $maxBytes = null,
    ): self {
        $clone = clone $this;
        $clone->maxNodes = $maxNodes ?? $clone->maxNodes;
        $clone->maxEdges = $maxEdges ?? $clone->maxEdges;
        $clone->maxDepth = $maxDepth ?? $clone->maxDepth;
        $clone->maxStringLength = $maxStringLength ?? $clone->maxStringLength;
        $clone->maxBytes = $maxBytes ?? $clone->maxBytes;

        return $clone;
    }

    /**
     * Add a host rule. Receives the raw schema array, returns any issues.
     *
     * The extension point that keeps hosts from forking this class: a rule can
     * assert anything about the graph, and runs alongside the built-in checks
     * rather than replacing them.
     *
     * @param  Closure(array<string,mixed>): list<ImportIssue>  $rule
     */
    public function addRule(Closure $rule): self
    {
        $clone = clone $this;
        $clone->rules[] = $rule;

        return $clone;
    }

    /**
     * Every problem with this schema. Empty means it may be accepted.
     *
     * Returns rather than throws so a UI can show all of them at once; use
     * {@see assert()} at the boundary where you just need it to stop.
     *
     * @param  array<string,mixed>  $schema
     * @return list<ImportIssue>
     */
    public function inspect(array $schema): array
    {
        $issues = [];

        // Byte size first: everything below walks the structure, and there is
        // no reason to walk a payload already too large to accept.
        $encoded = json_encode($schema);
        if ($encoded === false) {
            return [ImportIssue::error('The graph could not be encoded as JSON, so it cannot be stored or replayed.')];
        }

        if (strlen($encoded) > $this->maxBytes) {
            return [ImportIssue::error("The graph is larger than the {$this->maxBytes}-byte limit.")];
        }

        $graph = $schema['graph'] ?? $schema;
        $nodes = is_array($graph['nodes'] ?? null) ? $graph['nodes'] : [];
        $edges = is_array($graph['edges'] ?? null) ? $graph['edges'] : [];

        if (count($nodes) > $this->maxNodes) {
            $issues[] = ImportIssue::error(count($nodes)." nodes exceeds the limit of {$this->maxNodes}.");
        }

        if (count($edges) > $this->maxEdges) {
            $issues[] = ImportIssue::error(count($edges)." edges exceeds the limit of {$this->maxEdges}.");
        }

        $seen = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                $issues[] = ImportIssue::error('A node is not an object.');

                continue;
            }

            $id = is_string($node['id'] ?? null) ? $node['id'] : null;

            if ($id === null || $id === '') {
                $issues[] = ImportIssue::error('A node has no id.');

                continue;
            }

            if (isset($seen[$id])) {
                // Every id-keyed decision downstream — claims, checkpoints,
                // resume — becomes ambiguous with a duplicate.
                $issues[] = ImportIssue::error("Duplicate node id \"{$id}\".", $id);
            }

            $seen[$id] = true;

            $kind = $node['kind'] ?? $node['type'] ?? null;
            if (! is_string($kind) || $kind === '') {
                $issues[] = ImportIssue::error('A node has no kind.', $id);
            } else {
                foreach ($this->kindIssues($kind, $id) as $issue) {
                    $issues[] = $issue;
                }
            }

            foreach ($this->valueIssues($node, $id, 0) as $issue) {
                $issues[] = $issue;
            }
        }

        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                $issues[] = ImportIssue::error('An edge is not an object.');

                continue;
            }

            $edgeId = is_string($edge['id'] ?? null) ? $edge['id'] : null;

            foreach (['source', 'target'] as $end) {
                $ref = $edge[$end] ?? null;
                if (! is_string($ref) || ! isset($seen[$ref])) {
                    $issues[] = ImportIssue::error(
                        "An edge points at a {$end} node that does not exist.",
                        null,
                        $edgeId,
                    );
                }
            }
        }

        foreach ($this->rules as $rule) {
            foreach ($rule($schema) as $issue) {
                $issues[] = $issue;
            }
        }

        return array_values($issues);
    }

    /**
     * Throw unless the schema satisfies this policy.
     *
     * @param  array<string,mixed>  $schema
     *
     * @throws UnsafeGraph
     */
    public function assert(array $schema): void
    {
        $issues = array_values(array_filter($this->inspect($schema), static fn (ImportIssue $i): bool => $i->isError()));

        if ($issues !== []) {
            throw UnsafeGraph::from($issues);
        }
    }

    /**
     * @return list<ImportIssue>
     */
    private function kindIssues(string $kind, string $nodeId): array
    {
        // Compare on the BARE name after resolving every id this kind answers
        // to. `@particle-academy/api_request` and `api_request` are the same
        // executor; a policy that only knew the string it was handed would be
        // bypassed by spelling it the other way.
        $bare = KindId::bare($kind);
        $aliases = Builtin::kindIdIndex()[$bare] ?? [];
        $names = array_values(array_unique([$bare, ...array_map(KindId::bare(...), $aliases)]));

        foreach ($names as $name) {
            if (in_array($name, $this->denied, true)) {
                return [ImportIssue::error("The kind \"{$kind}\" is not permitted here.", $nodeId)];
            }
        }

        if ($this->allowed === null) {
            return [];
        }

        foreach ($names as $name) {
            if (in_array($name, $this->allowed, true)) {
                return [];
            }
        }

        return [ImportIssue::error("The kind \"{$kind}\" is not on the allowed list.", $nodeId)];
    }

    /**
     * Walk a value for depth, oversized strings and hostile bytes.
     *
     * @return list<ImportIssue>
     */
    private function valueIssues(mixed $value, string $nodeId, int $depth): array
    {
        if ($depth > $this->maxDepth) {
            // Depth is checked before recursing further, so a nesting bomb is
            // refused rather than parsed. Whatever reads this next — a JSON
            // decoder, a serializer, a template — recurses too.
            return [ImportIssue::error("A node's config nests deeper than {$this->maxDepth} levels.", $nodeId)];
        }

        if (is_string($value)) {
            if (strlen($value) > $this->maxStringLength) {
                return [ImportIssue::error("A string in this node is longer than {$this->maxStringLength} characters.", $nodeId)];
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                return [ImportIssue::error('A string in this node is not valid UTF-8.', $nodeId)];
            }

            // NUL and the C0/C1 control ranges, minus tab, newline and carriage
            // return, which are legitimate in a prompt or a description.
            if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\xC2\x80-\xC2\x9F]/u', $value) === 1) {
                return [ImportIssue::error('A string in this node contains control characters.', $nodeId)];
            }

            return [];
        }

        if (! is_array($value)) {
            return [];
        }

        $issues = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                foreach ($this->valueIssues($key, $nodeId, $depth + 1) as $issue) {
                    $issues[] = $issue;
                }
            }

            foreach ($this->valueIssues($item, $nodeId, $depth + 1) as $issue) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }
}
