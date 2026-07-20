# Changelog

Notable changes to `particle-academy/fancy-flow-php`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## 0.8.0

Mirrors fancy-flow 0.16.0. All of it comes from the MOIC Suite consumer's review
— the only consumer actually running the split (TS editor, PHP execution).

### BREAKING — `WorkflowResolver` takes a version

```php
public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null;
```

A workflow another workflow depends on is an **interface, and interfaces need
pins**. Without one, a parent goes on calling `invoice-triage`, someone edits
that child, and the parent runs different logic *having reported success the
whole time* — correct-looking, no error, wrong behaviour. Before this **no host
could implement pinning**, because the node had no way to ask and the resolver
no way to receive.

`missing` and `version-mismatch` are distinct on purpose: reporting a mismatch
as "not found" sends an author hunting for a workflow that is sitting right
there, and a mismatch error should name both versions.

**What to do:** callers are unaffected. If you IMPLEMENT `WorkflowResolver`, add
the optional parameter and widen the return type. Done now because the
population of implementers is approximately one; later it would not have been.

`EloquentWorkflowResolver` honours a pin, and reports a pinned-but-absent
version as a mismatch naming the version it does hold.

### BREAKING — manifest shape

- The engine range moved **into each runtime**: one range could not say "needs
  ts >=0.16 **and** php >=0.8", so a package installed cleanly against a host
  whose *other* runtime was too old. A leftover top-level `fancyFlow` is now an
  explicit error rather than ignored.
- `capabilities` is a map with a requirement level (`{"llm": "required"}`), not
  a bare list. `required` + unwired is an **error**, surfaced at author time so
  an editor can grey the node instead of it silently no-opping mid-run.

### Added

- `subflow` takes an optional `version` pin.
- Manifest `aliases`, `configVersion`, `sideEffects`, `pausesForHuman`.
- `NodeManifest::satisfiesRange()` — a small semver check pinned clause-for-clause
  against the TS implementation. An unparseable range is treated as unsatisfied,
  so it fails loudly rather than waving a node through.
- **Fixtures:** capability stubs declared as data (both engines build the same
  fake from the same JSON — otherwise parity theatre), pause/resume cases,
  event assertions, legacy-alias cases, and **at least one failure or pause case
  is now required to publish**.

Verified across runtimes rather than asserted: one fixture file through both
engines returns identical verdicts, including which cases failed.

---

## 0.7.0

### Added - the human-pause contract (`FancyFlow\Runtime\Pause`)

A run waiting for a person is not a failure, but it travels the same channel as
one: the executor aborts, the runner records a reason, and `RunWorkflowJob`
decides what that reason meant.

Until now that decision was **two `str_starts_with` checks** against
`PAUSE_PREFIX` constants owned by two BUILTIN executors. A third-party
human-input node could not participate at all - its pause fell through to the
failure path and the queue retried it until it exhausted its tries. Reported by
the MOIC Suite consumer, who needed exactly that seam.

```php
// In a node:
$values = $ctx->inputs['values'] ?? null;
if ($values === null) {
    $ctx->pauseForHuman('signature', ['document' => 'nda.pdf']);
}

// In a runner:
if ($pause = Pause::decode($result->error)) { /* park it, do not fail it */ }
```

The wire format is **byte-identical to the TypeScript twin** - pinned by tests
against strings produced by `@particle-academy/fancy-flow`'s `encodePause()`,
and verified in both directions. That is what lets a consumer author in TS and
execute here without pause semantics quietly diverging.

`awaiting` is open, not an enum: `approval` and `input` are what the builtins
emit, but a marketplace node may define its own.

### Added - third-party waits are first-class

- `WorkflowRun::AWAITING_HUMAN` - status for a wait this package does not
  define. Approval and input keep their own statuses, because hosts already
  query on them.
- New nullable columns `awaiting_kind` + `awaiting_detail` (additive migration),
  so a host can render a prompt for a wait it has never heard of.
- `WorkflowRun::awaitingKind()`, `isAwaitingHuman()`, `submitHuman()`.
- `WorkflowSettled::AWAITING_HUMAN`; `isAwaitingHuman()` now covers it.
- `NodeKind::$pausesForHuman` - a kind declares its wait, readable WITHOUT
  running the graph, so a host learns it needs a resume path before the first
  run parks itself forever. Declared on `user_input` and `human_approval`.

### Added - marketplace contracts (`FancyFlow\Marketplace`)

- `NodeManifest` - validates a node package manifest, agreeing kind-for-kind
  with the TS validator. `checkRuntimeSupport()` is the check that makes a
  TS-only package visible to a PHP host BEFORE install rather than at the first
  run.
- `FixtureRunner` - runs a node's golden fixtures here. A case asserts that
  **the downstream node executed**, not the port the node recorded, because a
  recorded-port assertion stays green while no edge fires and the run reports
  success having done nothing.

### Fixed

- Both runners honour a case's declared `ports`. TS derives config-driven ports
  by running a JS function and PHP cannot, so without this the identical fixture
  built a different graph on each runtime - the fixtures silently stopped
  comparing like with like. Requires fancy-flow >= 0.15.1.

### Nothing breaks

The pre-contract `awaiting-approval:` / `awaiting-input:` prefixes are still
decoded, and both constants remain (deprecated). Runs parked by an older version
still resume, and a node built against the old private constant keeps working -
there is a test for each.

**What to do:** run `php artisan migrate` for the two new nullable columns.
Existing pause code needs no change.

---

## 0.6.0

### BREAKING — the PHP floor is now 8.3

`require.php` moves from `^8.2` to `^8.3`. Composer will refuse to install this
release on PHP 8.2.

**What to do:** upgrade to PHP 8.3 or later, or pin to `^0.5` and stay there.

**Why:** the `laravel/ai` adapter shipped in 0.5.0 requires PHP 8.3, so with an
8.2 floor it could not sit in `require-dev` at all. Its tests were therefore
skipped everywhere except one bespoke CI job — thin coverage for one of the two
adapters we ship specifically so nobody has to hand-roll an LLM client. Raising
the floor puts it under test in the normal matrix and on every contributor's
machine. PHP 8.2's active support ended in December 2024.

### Changed

- CI matrix is now 8.3 + 8.4, and the separate `laravel/ai` job is gone —
  the matrix covers it.
- `laravel/ai` joins `prism-php/prism` in `require-dev`. Neither is a runtime
  dependency; `require` remains `{"php": "^8.3"}`.

---

## 0.5.0

### BREAKING — builtin kind ids are namespaced

Canonical kind ids move from bare names to `@particle-academy/<name>` —
`switch_case` becomes `@particle-academy/switch_case`.

**What to do:** most likely nothing. Every bare name is registered as an alias,
so saved documents keep opening, `NodeKindRegistry::get('switch_case')` keeps
resolving, and executors bound under bare names keep matching. Export writes the
canonical id, so graphs converge as they are re-saved.

You only need to act if you compared a kind id with `===` against a bare
string, or used one as an array key expecting the exact literal back.

**Why now:** `kind` is persisted inside every saved document. The moment two
packages both ship a node called `llm_branch`, stored graphs are ambiguous and
there is no migration path, because the ambiguous string is already written into
the document. This is cheap today and impossible later.

### BREAKING — `llm_branch` is renamed to `llm_router`

Canonical id `@particle-academy/llm_router`, label "LLM Router".

**What to do:** nothing required. `llm_branch`, `@fancy/llm_branch` and
`llm_router` all resolve, and config keys are unchanged (`routes[].port`,
`fallback`, `provider`, `model`, `system`, `prompt`).

**Why:** the palette said "Router", the id said "branch", and the config key is
`routes` — three vocabularies for one node. The node picks one of N named
routes; it is not a two-way branch.

### Added

- **Capability seam** — `LlmClient` and `WorkflowResolver`. Core declares the
  contract; the host supplies the implementation. Register via a static setter
  (framework-free) or the Laravel container.
- **Two shipped LLM adapters** — Prism and `laravel/ai`. Exactly one installed
  wires itself with no configuration; both installed requires
  `fancy-flow.llm.driver`; neither aborts naming what to install. A client you
  register yourself always wins.
- **`llm_router` executor** — a shuttle, not an engine: it carries the declared
  routes out to the host's client and the choice back. A port the model invents
  never routes (it goes to `fallback`, else the first declared route, always
  with a warning), and the reason travels with the value as
  `{route, reason, input}`.
- **`subflow`** — run another workflow, with `output` / `stream` / `both` modes,
  child progress surfaced as tagged log lines against the subflow node, and a
  depth guard that names the offending reference instead of overflowing the
  stack.

### Fixed

- Wiring capabilities in `boot()` eagerly resolved the workflow resolver, which
  dragged the `NodeKindRegistry` singleton into existence before
  config-declared kinds were read. Now resolved lazily through container
  proxies.

---

## 0.4.2

### Fixed — cross-runtime port parity

`activatedPorts` now falls back to the node KIND's declared ports before
falling back to a lone `out`.

fancy-flow 0.9.0 made the TS runtime resolve ports through the kind, including
config-driven kinds. This runtime read only the node's own ports. Before that
change both fell back to `out` and agreed; afterwards the same `WorkflowSchema`
could route differently on Node and PHP.

**Scope, corrected:** only kinds that rely on DECLARED ports to fan out were
affected. An executor returning an explicit port via `Port::only(...)` (or a
`__port` / `branch` key) short-circuits before the fallback and was never
affected. An earlier advisory implied branch edges would stop firing generally;
that was too broad.

Only **non-empty** kind ports are adopted — a terminal kind declares an empty
list, and consuming that literally would publish zero ports where the historical
fallback published `out`.

---

## 0.4.1

### Added — `WorkflowSettled`

Dispatched on every exit path of a durable run — completed, awaiting_approval,
awaiting_input, errored — exactly one per in-process attempt, carrying the
outcome plus `isTerminal()` / `isAwaitingHuman()`.

`WorkflowStarted` always fired, but only success dispatched a terminal event, so
anything a host bound for a run's duration (an ambient run context, a listener,
a log scope) was never torn down when a run paused, failed, or threw — and
leaked onto the queue worker.

**Bind teardown to `WorkflowSettled`**; `WorkflowFinished` / `WorkflowFailed`
remain for reporting. `failed()` now dispatches `WorkflowFailed` too — terminal
failure after retries was previously written to the database and announced to
nobody.
