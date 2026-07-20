# AGENTS.md — fancy-flow-php

PHP runtime for `fancy-flow` workflow graphs. The framework-free twin of
`@particle-academy/fancy-flow`'s TypeScript engine. `CLAUDE.md` symlinks here.

## What this package is

A faithful **port**, not a redesign. The source of truth for behavior is
`@particle-academy/fancy-flow` (in the envelope at `repos/fancy-flow`): its
`src/runtime/run-flow.ts`, `src/schema/workflow-schema.ts`, `src/registry/*`.
When those change, this mirror tracks them kind-for-kind via the parity fixtures.

The guarantee: **same `WorkflowSchema` JSON in, same `RunResult.outputs` out** on
Node and PHP. Don't break it.

## Architecture (framework-free core)

- `Workflow` — import/export/validate WorkflowSchema v1.
- `Engine\FlowRunner` — the `runFlow` port (Kahn topo, ports, branching, cycles,
  timeout). **Note:** a node runs when ≥1 incoming edge is active (merge-after-
  decision, `#1`), and `collectInputs` reads only active edges — that's the
  documented contract; don't regress either.
- `NodeKindRegistry` + `Registry\{NodeKind, ConfigField, Builtin}` — the kinds.
- `ExecutorRegistry` + `Contracts\{NodeExecutor, Resolver}` — behavior; resolves
  node id → kind → `*`.
- `Runtime\{ExecutionContext, RunEvent, RunOptions, RunResult, Port, ...}`.
- `Nodes\<Domain>\*Executor` — the 24 default executors, grouped by domain.
- `Nodes\Support\*` — injectable client interfaces + deterministic fakes + the
  `Expr` `{{ path }}` resolver (safe, no arbitrary eval).
- `Capabilities\*` — the HOST capability seam: `LlmClient` (`chooseRoute`, used
  by `llm_router`) and `WorkflowResolver` (used by `subflow`), plus the shipped
  `Adapters\{PrismLlmClient, LaravelAiLlmClient}` and the auto-detector. Both
  libraries are `suggest`-only + `class_exists()`-guarded — **core's `require`
  must stay PHP-only**. `FakeLlmClient` keeps flow tests offline.
- `Registry\KindId` — the id convention. Canonical ids are
  `@particle-academy/<name>`; old spellings live on as `aliases`. **Anything
  keyed by kind name must key on EVERY id a kind answers to** — registry lookups
  and executor bindings both do, and a rename like `llm_branch` → `llm_router`
  is only survivable because of it.

## Conventions

- **PHP 8.3 floor.** Immutable value objects, named-arg constructors, `match`.
- **No new runtime dependencies** in the core. Injectable clients over hard deps.
- **Faithfulness first.** If in doubt, match `run-flow.ts` semantics and add a
  parity fixture. Where the PHP twin intentionally differs from the TS *code*
  (never the TS *contract*), say so in a comment (see `collectInputs`).
- **Regenerate fixtures deliberately.** They're golden files; only regenerate
  when behavior legitimately changes, and eyeball the diff.

## Commands

```bash
composer install
composer test            # pest — unit + parity
vendor/bin/pest tests/Unit/EngineTest.php   # one file
```

## Publishing

PHP package — auto-syncs to Packagist from git tags (no publish workflow). Ship =
bump version → commit → tag `vX.Y.Z` → push tag → Packagist picks it up. First
publish requires a one-time Packagist submit + GitHub webhook. Then advance the
envelope pin. See the envelope's `.ai/knowledge/publishing.md`.

## Roadmap

0.1 core → 0.2 Laravel layer → 0.3 durable + agentic → 0.4 durable human
input (`user_input` pause/resume) → 0.5 capabilities + namespaced kind ids
(`llm_router`, `subflow`, shipped LLM adapters) → 0.6 Human+ (Reverb broadcast +
MCP bridge).
Plan: envelope `.ai/plans/fancy-flow-php.md`.
