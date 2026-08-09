# Changelog

Notable changes to `particle-academy/fancy-flow-php`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## 0.14.1 — 2026-08-09

### Added

- Six cases to the `satisfiesRange` table, matching `fancy-flow` and
  `fancy-ui-cli`. Two pin places where this convention **deliberately differs
  from standard semver**: `1.2.3-beta.1` satisfies `^1.2` here and not under
  npm's `semver`, and `^0.0.1` admits `0.0.2` where standard semver pins it
  exactly.

  All three implementations were run against a shared case set and agree on
  every case. The gap is a future one: a fourth implementation reaching for a
  stock semver library would disagree on exactly these two.

## 0.14.0 — 2026-08-07

### Added

- **`FancyFlow\Laravel\LiveContract`** — the PHP half of `flowLive` in
  `@particle-academy/fancy-flow`. Declares which events describe a run's durable
  state and which client query keys each invalidates. A parity test on each side
  asserts the two lists match.

  It covers a run, **not per-node chatter**: `NodeStatusChanged` and
  `NodeOutput` fire per node, many times a second on a wide graph, and a log
  line is a stream rather than a cache entry.

  `SOURCES` maps each contract event to the in-process event class it
  corresponds to, so a host wiring broadcasting knows what to listen for.

  **Broadcast status:** the events in `FancyFlow\Laravel\Events` are dispatched
  in-process and none implements `ShouldBroadcast`. This constant is the agreed
  vocabulary, not a description of traffic already on the wire — a host wanting
  live runs re-broadcasts under these names. Making them broadcast natively is a
  separate change, because it turns on websocket traffic for every consumer.

  **What you must do:** nothing. Additive; nothing reads it unless a host wires
  it up.

## 0.13.0 — 2026-08-07

### Fixed

- **A human gate could be skipped entirely, running the flow past the person it
  was waiting for.** `user_input` and `human_approval` decided whether to pause
  by reading their own input port — `values` / `approved` — and paused only when
  it was `null`. That conflated two different questions: *has a person answered
  this?* and *is there data on this port?* Anything that put a value there
  answered the second and skipped the gate: the host's own `initial_inputs`, an
  upstream edge, or an answer recorded before the node ever ran.

  On the `per_node` driver this was reachable in production. A host frontend
  posting an empty submit could win the race against the queued run, so a
  submission existed before the node executed, the executor saw a non-null
  `values`, and the form a person was meant to fill never appeared — downstream
  nodes then ran on empty input. On `single` the run executes inline and reaches
  the node first, which is why the same graph always paused there. Reported in
  #3, with the race narrowed by the reporter.

  **A human node now pauses because it is a human node.** Resumption is decided
  by whether the run has a recorded answer for that node, never by what is on
  the port. Pre-filled inputs cannot satisfy it.

- **An answer could be recorded for a node the run was not parked on.**
  `submitInput()`, `approve()` and `deny()` wrote to `submissions` / `approvals`
  for any node id at any time, then re-queued the run — which is how a stale
  answer got in front of a node in the first place. They now throw
  `NotAwaitingHuman` unless the run is actually parked on that node.

  Throwing rather than ignoring is deliberate: a submission that silently
  vanishes is the mirror-image bug, where a person fills in a form and nothing
  happens.

  Also fixes `approve()` / `deny()` coercing a null `awaiting_node` to `""` and
  recording an answer under an empty key.

### Added

- **`autoAnswerFromInput`** on `user_input` and `human_approval` — off by
  default. Turning it on restores the old behaviour deliberately: a value
  already on the port answers on the person's behalf and the node does not
  pause. Wanted for a step that is a form when a human is present and a
  pass-through when an upstream node already computed the answer.

  It is a config flag rather than the default because the old behaviour cannot
  be told apart from the failure it caused. Naming it puts the decision in the
  graph, where it is reviewable. Weigh it harder on `human_approval`: turning it
  on there means the graph, not a person, is the approver.

### Changed

- **BREAKING (behaviour) — a pre-filled `values` / `approved` port no longer
  resumes a human node.**

  **What you must do:** if you relied on that — passing a human node's answer in
  through `initial_inputs` or an upstream edge — set `autoAnswerFromInput: true`
  on that node and behaviour is unchanged. If you did not, nothing to do, and a
  gate that used to be skippable no longer is.

- **BREAKING (behaviour) — recording an answer for a node the run is not parked
  on now throws.**

  **What you must do:** submit only while the run is parked on that node
  (`isAwaitingHuman()` / `awaiting_node`). A host that fires a submit
  speculatively — before the run has paused — must stop; that pattern is what
  skipped the gate. Catch `FancyFlow\Exceptions\NotAwaitingHuman` if a
  double-submit is reachable from your UI.

Third-party pausing nodes are unaffected: recorded answers are still merged onto
their input ports, because that is the only resume channel a marketplace kind
calling `pauseForHuman()` has.

## 0.12.0 — 2026-08-07

### Changed

- **BREAKING — PHP 8.3 is no longer supported.** `require.php` moves from `^8.3` to `^8.4`.

  **What you must do:** on PHP 8.4 or newer, nothing. On 8.3, either upgrade PHP first or stay on the previous release — it keeps working and is unaffected by this.

- CI now tests PHP 8.4 only, instead of a matrix spanning versions this package no longer claims to support. A matrix that tests what the manifest forbids is worse than none — it reports green for a combination nobody can install.

### Why

These are the kit 0.5 platform floors. The suite was split across PHP 8.2 and 8.3 with the framework spanning 11–13, so no package could rely on anything newer than its weakest sibling. Every PHP package in the kit takes the same floors at once, so a consumer never has to resolve a mix.

Pre-1.0, so this lands in a MINOR. **No API changed, nothing was removed, nothing was renamed** — only what the package requires.

## 0.11.0

### Changed

- **BREAKING — `per_node` is now the DEFAULT queue driver.** 0.10 shipped it
  behind `fancy-flow.queue.driver`, defaulting to `single`, so upgrading to 0.10
  changed nothing: the durability defect it fixed stayed live for every host that
  did not know to opt in, and nobody opts into a flag they have not read about.

  **What you must DO: run `php artisan migrate`** if you have not since 0.10 —
  `per_node` needs the `workflow_run_nodes` table, and the package's migrations
  are auto-loaded, so there is nothing to publish first.

  Beyond that, most hosts do nothing:

  - **A run already in flight is safe.** The new driver *adopts* a checkpoint
    written by the old one instead of replaying it, so an upgrade mid-run does
    not re-execute completed nodes.
  - **`FANCY_FLOW_QUEUE_DRIVER=single` restores the old behaviour exactly.** That
    driver is unchanged, keeps its own test suite, and is **not deprecated**.
  - Runs are now carried by `AdvanceWorkflowJob` + `RunNodeJob` rather than a
    single `RunWorkflowJob`. If you queue-monitor by job class name, or assert on
    it in tests, those names change. Every entry point still routes through
    `RunWorkflowJob::enqueue()`.
  - `queue.tries` now applies **per node** rather than per run, so one transient
    node failure no longer takes a whole graph down. Nodes declaring
    `sideEffects: unsafe-to-replay` still get exactly one attempt regardless of
    `tries`, because a retried `git_pr_open` opens a second pull request.

  Why the default moved rather than staying opt-in: under `single`,
  `node_outputs` was written in exactly one place — **after** the whole graph
  returned. A worker killed mid-run (timeout, deploy, OOM, `SIGKILL`)
  checkpointed nothing, so the retry resumed from the *previous* checkpoint and
  re-ran every node that had completed in the killed attempt. A fix nobody
  receives is not a fix.

### Fixed

- **`DurableTestCase` tested whichever driver happened to ship.** It never pinned
  `fancy-flow.queue.driver`, so it inherited the shipped default — and the moment
  that default flipped, three of its tests failed and the rest quietly repointed
  at the other driver while keeping names that claimed otherwise. The failures
  were the lucky part. It now pins `single` explicitly, the way `PerNodeTestCase`
  always pinned `per_node`, so the two drivers stay independently covered.

293 tests passing, both drivers covered separately, and the 22 parity fixtures
still reproduce their golden outputs through the per-node driver.

## 0.10.0

### Added

- **One queued job per NODE — the `per_node` queue driver.** A durable run was
  one job for the whole graph, and `node_outputs` was written in exactly one
  place: **after** `$flow->run()` returned. So the checkpoint only existed for
  runs that ended by returning. The failure long workflows actually hit — a
  worker killed by a timeout, a deploy, an OOM, a `SIGKILL` — never reached that
  write at all, and the retry resumed from the *previous* checkpoint and re-ran
  every node that had completed in the killed attempt. For nodes declaring
  `sideEffects: unsafe-to-replay` (`git_pr_open`, `git_push`, `git_pull`,
  `git_checkout`) that is not wasted work: it opens a second pull request.

  Two more failures share that shape. A run outliving `queue.retry_after` was
  released while still executing, so a second worker ran the same run
  concurrently and every node ran twice. And with `queue.tries = 1` a single
  transient node failure took the whole run down, because one setting covered an
  entire graph.

  The new driver splits a run into `AdvanceWorkflowJob` (work out what can run
  now) and `RunNodeJob` (run exactly one node, checkpoint it, hand back):

  ```php
  // config/fancy-flow.php
  'queue' => ['driver' => 'per_node'],
  ```

  - **A killed worker loses at most one node** — the one in flight. Every
    completed node wrote its own output as it finished.
  - **The claim is enforced by the database.** `(run_key, node_id)` is unique in
    the new `workflow_run_nodes` table and claiming is an insert against that
    constraint, so two workers computing the same node as ready is decided by
    the database rather than by which one checked first. Losing the race is a
    no-op, not an error.
  - **Real fan-out.** Independent branches become separate jobs on separate
    workers instead of a sequential walk inside one process.
  - **Retries are per node.** A kind declaring `sideEffects: unsafe-to-replay`
    gets exactly one attempt regardless of `queue.tries`; a flaky HTTP or LLM
    node can be given several via `queue.node_tries` without putting its
    neighbours at risk.
  - **Bounded worker occupancy.** A twelve-hour workflow is no longer a
    twelve-hour job.

  `Engine\FlowRunner` is untouched — the parity fixtures are what prove it. The
  driver does not reimplement the engine's routing either: it runs each node
  *through* the engine with the completed nodes fed back as `resumeOutputs`, and
  reads the activated ports off the engine's own `node-output` events.

  **What you must DO: nothing.** `queue.driver` defaults to `single` — the
  existing behaviour, unchanged — so upgrading changes how nothing runs. Switch
  when you want the durability, and run the new migration first
  (`php artisan migrate`; re-publish with `--tag=fancy-flow-migrations` if you
  vendored them). A run parked mid-flight under `single` adopts its existing
  `node_outputs` checkpoint when it resumes on `per_node`, so switching does not
  replay what already ran.

- **`NodeKind::$sideEffects`** — `none` | `idempotent` | `unsafe-to-replay`, the
  same vocabulary a node package already declares in its manifest, lifted onto
  the kind so it is readable from the registry without loading one. Declarable
  through `#[FlowNode(sideEffects: 'unsafe-to-replay')]`, a config kind, or a
  kind manifest. This is what the per-node driver reads to pin a node to a
  single attempt; nothing else consults it, and an undeclared kind behaves
  exactly as before.

- **`queue.drain_limit`** — one `RunNodeJob` may keep going inline while the
  next step is unambiguous (exactly one ready successor, single-attempt, no
  human wait, not `unsafe-to-replay`), collapsing a chain of fast nodes back
  into one job. Fan-out always dispatches, so parallelism is never traded away.
  **Off by default** (`0`): it trades a little of the durability the driver
  exists to provide for latency, and that should be chosen rather than
  inherited.

- **`WorkflowRun::nodes()`** — the per-node rows, each with status, output,
  activated ports, attempt count, and timings. Empty under `single`.

### Changed

- **BREAKING (`per_node` only): `WorkflowSettled` is per RUN, not per attempt.**
  Under `single` it fires once per in-process attempt, in a `finally` — a job
  that throws and is retried emits one per attempt. Under `per_node` an
  "attempt" no longer exists at run scope, so it fires when the RUN settles:
  completed, failed, skipped, or parked on a human. It still pairs with
  `WorkflowStarted`, which fires when the run starts and again when it resumes
  from a pause.

  **What you must DO:** nothing unless you both switch to `per_node` *and* bind
  per-attempt state to `WorkflowSettled`. If you do, that binding now has a
  run-shaped lifetime rather than a job-shaped one — which is usually what was
  wanted anyway.

- **BREAKING (`per_node` only): `RunEvent`-derived events no longer arrive in
  topological order.** One process walking a graph produced a total order for
  free. Under fan-out, `NodeStatusChanged` / `NodeOutput` from independent
  branches interleave. Saying so rather than pretending otherwise: if you were
  relying on arrival order to reconstruct the graph, use the edges.

  **What you must DO:** nothing on `single`. On `per_node`, order by the node,
  not by the event.

- **BREAKING (`per_node` only): `fancy-flow.timeout_ms` is checked between
  nodes by the driver, not inside a single engine run.** It still bounds the
  whole run — measured from the first node claim — and, exactly as the engine
  does, it does not interrupt a node already executing.

  **What you must DO:** nothing. The meaning is the same; only the place it is
  enforced moved.

- The cohort `TriggerGuard` is re-checked once, when a run starts, rather than
  before every node. Re-asking it mid-run would let a change of state skip a
  half-executed workflow, which is worse than either answer.

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
