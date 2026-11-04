<?php

declare(strict_types=1);

namespace FancyFlow\Analysis;

use Closure;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\KindId;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Schema\FlowEdge;
use FancyFlow\Schema\FlowNode;
use FancyFlow\Schema\ImportIssue;
use FancyFlow\Workflow;
use Throwable;

/**
 * Compare each `{{ in.field }}` in a graph to the fields its upstream step
 * declares, without running anything.
 *
 * The failure this catches: a template naming a field that does not exist runs
 * green and renders empty, because an unresolved path is `''` by default. The
 * engine can only report that during a run. The declaration that answers the
 * question has been on the kinds for a long time; nothing compared them at save.
 *
 * ## Unknown refuses nothing
 *
 * {@see NodeKind::$outputShape} has three states, and the check honours all of
 * them: `null` is NOT DECLARED and nothing can be said; `[]` declares no fields;
 * a list declares those fields. A kind nobody has described never produces a
 * finding, and neither does anything downstream of one, because a false rejection
 * is one an author cannot comply with. Anything this class cannot prove it leaves
 * alone: an entry point (its fields are whatever the host seeded), a head that is
 * not a wired port, a template that is not a plain dot-path.
 *
 * ## A warning unless a host asks
 *
 * `Workflow::import` reports these as warnings. `ok` is unaffected, so no existing
 * graph starts failing. A host that wants refusal passes
 * `templateReferences: ImportIssue::ERROR`, or adds {@see rule()} to its
 * `GraphPolicy` at the save boundary.
 *
 * Only the verdict is contract (`flow/template-references`). The message text is
 * not.
 */
final class TemplateReferences
{
    public const OFF = 'off';

    /**
     * Ports whose payload is NOT what the kind's `outputShape` describes.
     *
     * `for_each` declares the aggregate it publishes on `done`. `item` carries one
     * element of the list, which can be anything. A shape cannot express this per
     * port today, so it is named here rather than letting the shape over-apply.
     */
    private const UNSHAPED_PORTS = ['for_each' => ['item']];

    /**
     * @param  list<FlowNode>  $nodes
     * @param  list<FlowEdge>  $edges
     * @return list<array{nodeId:string,configKey:string,path:string,fields:list<string>}>
     */
    public static function findings(array $nodes, array $edges, NodeKindRegistry $registry): array
    {
        return (new self($nodes, $edges, $registry))->run();
    }

    /**
     * @param  list<FlowNode>  $nodes
     * @param  list<FlowEdge>  $edges
     * @param  ImportIssue::ERROR|ImportIssue::WARNING  $level
     * @return list<ImportIssue>
     */
    public static function check(array $nodes, array $edges, NodeKindRegistry $registry, string $level = ImportIssue::WARNING): array
    {
        return array_map(
            static fn (array $f): ImportIssue => new ImportIssue($level, self::message($f), nodeId: $f['nodeId']),
            self::findings($nodes, $edges, $registry),
        );
    }

    /**
     * The same check as a {@see \FancyFlow\Security\GraphPolicy::addRule()} rule,
     * so refusing at save needs no second extension point.
     *
     * @param  ImportIssue::ERROR|ImportIssue::WARNING  $level
     * @return Closure(array<string,mixed>): list<ImportIssue>
     */
    public static function rule(NodeKindRegistry $registry, string $level = ImportIssue::ERROR): Closure
    {
        return static function (array $schema) use ($registry, $level): array {
            $import = Workflow::import($schema, lenient: true, registry: $registry, templateReferences: self::OFF);

            return self::check($import->graph->nodes, $import->graph->edges, $registry, $level);
        };
    }

    /**
     * Every `{{ dot.path }}` in a string, in order.
     *
     * A scanner rather than a regex: this reads text it did not write, and a lazy
     * capture over an unterminated `{{` backtracks. Same segmentation as
     * {@see \FancyFlow\Nodes\Support\Expr}: an opener pairs with the next closer.
     * Anything that is not a plain dot-path (Expr resolves those to nothing, which
     * is a different mistake) is not returned.
     *
     * @return list<string>
     */
    public static function references(string $template): array
    {
        $out = [];
        $offset = 0;
        while (($open = strpos($template, '{{', $offset)) !== false) {
            $close = strpos($template, '}}', $open + 2);
            if ($close === false) {
                break;
            }
            $path = trim(substr($template, $open + 2, $close - $open - 2));
            if ($path !== '' && strspn($path, self::PATH_BYTES) === strlen($path)
                && ! str_starts_with($path, '.') && ! str_ends_with($path, '.') && ! str_contains($path, '..')) {
                $out[] = $path;
            }
            $offset = $close + 2;
        }

        return $out;
    }

    private const PATH_BYTES = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$-.';

    /** @var array<string,FlowNode> */
    private array $byId = [];

    /** @var array<string,list<FlowEdge>> */
    private array $incoming = [];

    /** @var array<string,list<string>|null> */
    private array $memo = [];

    /** @var array<string,true> */
    private array $visiting = [];

    /**
     * @param  list<FlowNode>  $nodes
     * @param  list<FlowEdge>  $edges
     */
    private function __construct(private readonly array $nodes, array $edges, private readonly NodeKindRegistry $registry)
    {
        foreach ($nodes as $node) {
            $this->byId[$node->id] = $node;
        }
        foreach ($edges as $edge) {
            $this->incoming[$edge->target][] = $edge;
        }
    }

    /** @return list<array{nodeId:string,configKey:string,path:string,fields:list<string>}> */
    private function run(): array
    {
        $findings = [];
        foreach ($this->nodes as $node) {
            if (($this->incoming[$node->id] ?? []) === []) {
                continue; // An entry point reads what the host seeded.
            }
            foreach ($this->templatesOf($node) as $configKey => $template) {
                foreach (self::references($template) as $path) {
                    $fields = $this->missing($node, $path);
                    if ($fields !== null) {
                        $findings[] = ['nodeId' => $node->id, 'configKey' => (string) $configKey, 'path' => $path, 'fields' => $fields];
                    }
                }
            }
        }

        return $findings;
    }

    /**
     * The strings on a node that may be templates: every top-level string, plus
     * `expression` cells in a repeater. An array value is NOT walked otherwise:
     * `Expr::evaluate` returns a non-string unchanged, and `subgraph.graph` holds
     * templates that belong to a different graph.
     *
     * @return array<string,string>
     */
    private function templatesOf(FlowNode $node): array
    {
        $out = [];
        $kind = $node->type === null ? null : $this->registry->get($node->type);
        $schema = [];
        foreach ($kind?->configSchema ?? [] as $field) {
            $schema[$field->key] = $field;
        }

        foreach ($node->config as $key => $value) {
            $field = $schema[$key] ?? null;
            if ($field?->type === 'credential') {
                continue;
            }
            if (is_string($value)) {
                $out[(string) $key] = $value;
            } elseif (is_array($value) && $field?->type === 'repeater') {
                $cells = [];
                foreach ($field->fields ?? [] as $sub) {
                    if ($sub->type === 'expression') {
                        $cells[] = $sub->key;
                    }
                }
                foreach ($value as $i => $row) {
                    foreach ($cells as $cell) {
                        if (is_array($row) && is_string($row[$cell] ?? null)) {
                            $out["{$key}.{$i}.{$cell}"] = $row[$cell];
                        }
                    }
                }
            }
        }

        return $out;
    }

    /**
     * The fields it could have named when `$path` is provably absent, else null.
     *
     * @return list<string>|null
     */
    private function missing(FlowNode $node, string $path): ?array
    {
        $segments = explode('.', $path);
        $edges = $this->edgesForHead($node, $segments[0]);
        $rest = array_slice($segments, 1);
        if ($edges === null || $rest === []) {
            return null;
        }

        $fields = $this->fieldsArriving($edges);
        if ($fields === null) {
            return null;
        }

        foreach ($fields as $field) {
            if (self::related($rest, explode('.', $field))) {
                return null;
            }
        }

        sort($fields, SORT_STRING);

        return $fields;
    }

    /**
     * Which edges a template head reads. Mirrors `FlowRunner::collectInputs`: a
     * payload lands under its target handle (default `in`), and a handle-less edge
     * is also addressable by its source node's id. `$json` and `$input` alias `in`
     * when it exists and otherwise the whole map, which is not judged.
     *
     * @return list<FlowEdge>|null null when the head cannot be proved to be a wire
     */
    private function edgesForHead(FlowNode $node, string $head): ?array
    {
        $incoming = $this->incoming[$node->id] ?? [];
        $port = $head === '$json' || $head === '$input' ? 'in' : $head;

        $onPort = array_values(array_filter($incoming, static fn (FlowEdge $e): bool => ($e->targetHandle ?? 'in') === $port));
        if ($onPort !== []) {
            return $onPort;
        }
        if ($port !== $head) {
            return null;
        }

        $bySource = array_values(array_filter($incoming, static fn (FlowEdge $e): bool => $e->targetHandle === null && $e->source === $head));

        return $bySource !== [] ? $bySource : null;
    }

    /**
     * The fields any of these edges may deliver. They rejoin on one port and only
     * one is live on a run, so a field is present if ANY source declares it, and
     * one unknown source makes the whole set unknown.
     *
     * @param  list<FlowEdge>  $edges
     * @return list<string>|null
     */
    private function fieldsArriving(array $edges): ?array
    {
        $fields = [];
        foreach ($edges as $edge) {
            $declared = $this->fieldsOf($edge->source, $edge->sourceHandle ?? 'out');
            if ($declared === null) {
                return null;
            }
            $fields = [...$fields, ...$declared];
        }

        return array_values(array_unique($fields));
    }

    /** @return list<string>|null */
    private function fieldsOf(string $nodeId, string $handle): ?array
    {
        $key = $nodeId.chr(0).$handle;
        if (array_key_exists($key, $this->memo)) {
            return $this->memo[$key];
        }
        if (isset($this->visiting[$key])) {
            return null; // A back edge says nothing about the first pass.
        }

        $this->visiting[$key] = true;
        $fields = $this->declaredBy($this->byId[$nodeId] ?? null, $handle);
        unset($this->visiting[$key]);

        return $this->memo[$key] = $fields;
    }

    /** @return list<string>|null */
    private function declaredBy(?FlowNode $node, string $handle): ?array
    {
        $kind = $node?->type === null ? null : $this->registry->get($node->type);
        if ($kind === null || in_array($handle, self::UNSHAPED_PORTS[KindId::bare($kind->name)] ?? [], true)) {
            return null;
        }

        // A shape closure reads author-supplied config, so it can throw on a
        // malformed one. That is not a finding about the reference.
        try {
            $shape = $kind->outputShapeFor($node->config);
            $relation = $kind->emitsFor($node->config);
        } catch (Throwable) {
            return null;
        }

        if ($shape === null && $relation === null) {
            return null;
        }

        $fields = [];
        foreach ($shape ?? [] as $field) {
            if (is_string($field['path'] ?? null) && $field['path'] !== '') {
                $fields[] = $field['path'];
            }
        }
        if ($relation === null) {
            return $fields;
        }

        $related = $this->relatedFields($node, $relation);

        return $related === null ? null : array_values(array_unique([...$fields, ...$related]));
    }

    /**
     * The fields a declared relation contributes. Each relation is a different
     * operation; see {@see NodeKind::$emits}.
     *
     * @return list<string>|null
     */
    private function relatedFields(FlowNode $node, string $relation): ?array
    {
        $incoming = $this->incoming[$node->id] ?? [];

        if ($relation === 'input') {
            $on = array_values(array_filter($incoming, static fn (FlowEdge $e): bool => ($e->targetHandle ?? 'in') === 'in'));

            return $on === [] ? null : $this->fieldsArriving($on);
        }

        if ($relation === 'inputs-merged') {
            return $incoming === [] ? null : $this->fieldsArriving($incoming);
        }

        if ($relation === 'input-map-merged') {
            if ($incoming === []) {
                return null; // Flat at an entry point: whatever the host seeded.
            }
            $heads = [];
            foreach ($incoming as $edge) {
                $heads[] = $edge->targetHandle ?? 'in';
                if ($edge->targetHandle === null) {
                    $heads[] = $edge->source;
                }
            }

            return array_values(array_unique($heads));
        }

        if (str_starts_with($relation, 'expression:')) {
            $template = $node->config[substr($relation, strlen('expression:'))] ?? null;

            return is_string($template) ? $this->fieldsOfExpression($node, $template) : null;
        }

        return null; // A relation this version does not know: say nothing.
    }

    /**
     * Only a string that is exactly one reference has a knowable shape; see
     * {@see NodeKind::expressionConfigKey()}.
     *
     * @return list<string>|null
     */
    private function fieldsOfExpression(FlowNode $node, string $template): ?array
    {
        $references = self::references($template);
        $trimmed = trim($template);
        if (count($references) !== 1 || ! str_starts_with($trimmed, '{{') || strpos($trimmed, '}}') !== strlen($trimmed) - 2) {
            return null;
        }

        $segments = explode('.', $references[0]);
        $edges = $this->edgesForHead($node, $segments[0]);
        $fields = $edges === null ? null : $this->fieldsArriving($edges);
        if ($fields === null) {
            return null;
        }

        $rest = array_slice($segments, 1);
        if ($rest === []) {
            return $fields;
        }

        // `{{ in.user }}` of a declared `user.email` is a value with `email`.
        // A bare `user` field is opaque, so what it holds is unknown.
        $prefix = implode('.', $rest).'.';
        $children = [];
        foreach ($fields as $field) {
            if (str_starts_with($field, $prefix)) {
                $children[] = substr($field, strlen($prefix));
            }
        }

        return $children === [] ? null : $children;
    }

    /**
     * True when one path is the other or a prefix of it. Deeper than a declared
     * field is allowed because the field may be an object nobody described.
     *
     * @param  list<string>  $path
     * @param  list<string>  $field
     */
    private static function related(array $path, array $field): bool
    {
        $shared = min(count($path), count($field));

        return array_slice($path, 0, $shared) === array_slice($field, 0, $shared);
    }

    /** @param array{nodeId:string,configKey:string,path:string,fields:list<string>} $finding */
    private static function message(array $finding): string
    {
        $available = $finding['fields'] === []
            ? 'the steps wired into it declare no fields'
            : 'the steps wired into it declare only: '.implode(', ', $finding['fields']);

        return sprintf(
            '`{{ %s }}` in config `%s` names a field that does not exist; %s. It will render empty, and the run will still report success.',
            $finding['path'],
            $finding['configKey'],
            $available,
        );
    }
}
