# Changelog

Notable changes to `particle-academy/fancy-flow-php`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## 0.9.1

### Changed

- **BREAKING (node manifests): a runtime declares `files`, not `entry` /
  `package`.** Marketplace nodes are **vendored**, not installed —
  `fancy-cli add node` copies a node's source into the project the way it copies
  a component's. `entry` and `package` described an npm/Composer install that no
  longer happens, so a manifest carrying them is now rejected rather than
  silently claiming an install path nothing honours.

  ```jsonc
  "ui": ["ui"],                                    // the React surface, always copied
  "runtimes": {
    "ts":  { "files": ["js"],  "engine": ">=0.30.0" },
    "php": { "files": ["php"], "engine": ">=0.9.0" }
  }
  ```

  `ui` is separate from `runtimes` on purpose: the editor is React on every
  host, so a Laravel project needs the React kind and does **not** need the
  TypeScript executor. Folding them together loses one or the other.

  **What you must DO:** nothing unless you authored a node manifest — the
  registry served none until now. If you did, replace each runtime's `entry` /
  `package` with `files`, and move the surface to a top-level `ui`.

## 0.9.0

### Added

- **Trigger cohorts — `FancyFlow::dispatchCohort()`.** Fan one event out to
  several workflows the old way (a loop over `dispatch()`) and you get N
  independent queue jobs in whatever order the worker picks them up. Nothing
  orders them, and nothing stops the second from running after the first has
  **deleted the record they were both fired for**. The second run then completes
  over missing input and reports *success* — no exception, no log line, a green
  row in the run list.

  A cohort makes that fan-out one thing:

  ```php
  FancyFlow::dispatchCohort(
      [$enrichFlow, $archiveFlow, $notifyFlow],   // declared order IS the run order
      ['trigger' => $deal->toArray()],            // snapshotted per run at dispatch
      guard: ['name' => 'record-exists', 'args' => ['model' => Deal::class, 'id' => $deal->id]],
  );
  ```

  - **Ordered** — runs carry `cohort_seq`, assigned at dispatch. Queue arrival
    stops deciding anything.
  - **Serialized** — only the head is enqueued; each run enqueues its successor
    when it settles, so run N+1 observes run N's side effects on purpose rather
    than by luck. A run parked on a human wait has *not* settled and holds the
    cohort until someone decides.
  - **Guarded** — the named `TriggerGuard` is re-checked immediately before each
    run starts, not at dispatch, because the whole hazard is what changed in
    between. A run whose guard fails is `skipped` with `skipped_reason` recorded.

  If `$archiveFlow` deletes the deal, `$notifyFlow` is skipped with
  "App\Models\Deal 41 no longer exists" instead of notifying about nothing.

  Three policies via `policy:` — `serial-guarded` (default), `serial` (ordered,
  unguarded), `parallel` (the old all-at-once behaviour, correct when the
  fan-out shares no state). A guard that throws or cannot be resolved **fails
  closed** and skips: a skip is visible and re-runnable, a run over missing state
  is neither. A run that *fails* does not cancel the cohort — "the run before me
  failed" is not an answer to "is my input still there", and the guard is asked
  either way.

- **`FancyFlow\Contracts\TriggerGuard`** — `passes()` / `reason()`. Guards are
  resolved by name from the container (register them under `fancy-flow.guards`),
  never serialized as closures, since a queued job may run in another process
  minutes later.

- **A built-in `record-exists` guard** covering the common case with no code:
  pass `model` + `id` (or `column`). Register your own under the same name to
  override it — for soft deletes or tenancy — without renaming every cohort.

- **`WorkflowRun::SKIPPED`** and a matching **`WorkflowSettled::SKIPPED`**
  outcome, so a host still tears down anything it bound for the run. `SKIPPED`
  counts as terminal in `WorkflowSettled::isTerminal()`.

- **Migration** adding `cohort_key` (indexed), `cohort_seq`, `cohort_policy`,
  `guard`, and `skipped_reason` to the runs table.

  **What you must DO:** run `php artisan migrate` (or re-publish the migrations
  if you vendored them). Everything else is additive — every column is nullable,
  `dispatch()` is untouched, and a run dispatched the ordinary way has no cohort
  and behaves exactly as before.

  The TypeScript twin is `runCohort()` in `@particle-academy/fancy-flow` 0.29.0
  — same policies, same guard semantics, same fail-closed rule, in-process rather
  than across a queue.

## 0.8.2

### Changed

- **The documented Prism is now `particle-academy/prism`**, Particle Academy's
  maintained fork. Upstream `prism-php/prism` has not shipped since **March
  2026** and is eleven minor versions behind; the README was still telling
  people to install it. The `suggest` entry and the dev dependency move with it.

  **No code changed and nothing breaks.** The fork carries the same
  `Prism\Prism\` namespace, the shipped `PrismLlmClient` imports
  `Prism\Prism\Prism` either way, and detection is `class_exists()`-guarded — so
  an existing install on upstream keeps working exactly as before. This package's
  `require` stays PHP-only.

  **What you must DO:** nothing, unless you want the maintained one. If you do
  switch, **remove `prism-php/prism` in the same commit you add the fork.** They
  provide the same namespace and the fork declares no `replace`, so Composer will
  install both quite happily and leave you with two copies of every
  `Prism\Prism\*` class.

### Security

- **`guzzlehttp/guzzle` 7.14.2 → 7.15.1** (dev only). Pulled in by the dependency
  change above, and it arrived carrying three medium advisories — URI fragments
  leaking into redirect `Referer` headers, host-only cookie scope not preserved,
  and unbounded response cookies. `composer audit` is clean again. Dev-only, so
  no consumer was exposed.

## 0.8.1

Parity coverage for a divergence that was real, documented, and never tested.

### Added

- **Parity fixture `23-merge-same-handle`** — two mutually-exclusive branches
  rejoining on the **same** target handle. `05-merge-after-decision` already
  covered a merge point, but via a `merge` node where each branch lands on its
  own handle (`a` / `b`) — a shape where the branches structurally *cannot*
  collide. The shape that can collide, and that appears in ordinary "route, then
  continue" graphs, had no fixture at all.

  This runtime always handled it correctly. **fancy-flow (TS) did not** until
  0.27.1: it assigned every incoming edge unconditionally, so a dead branch
  ordered last overwrote the live branch's value with `undefined` — silently,
  with the run still reporting success. The divergence was even described in
  `FlowRunner::collectInputs()`'s docblock; it had simply never been pinned by a
  test, which is exactly what parity fixtures exist to prevent.

### Fixed

- **Corrected that docblock.** It stated as present-tense fact that "the TS code
  assigns unconditionally". True until fancy-flow 0.27.1, misleading after it —
  a note about a divergence that no longer exists is worse than no note.

**Consumers need do nothing** — no behaviour change in this package.

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
