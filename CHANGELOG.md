# Changelog

Notable changes to `particle-academy/fancy-flow-php`.

**BREAKING** marks anything that can stop working on upgrade. This package is
pre-1.0, so breaking changes land in MINOR releases — read those entries before
upgrading.

---

## 0.50.0 — 2026-09-03

### Added

- **`#[FlowNode]` can now declare the whole non-rendering kind.** Five fields
  were missing from the attribute — `accent`, `defaultConfig`,
  `pausesForHuman`, `outputShape` and `emits` — so `toKindArray()` never
  emitted them and discovery silently lost them. A human-pausing kind
  registered as `pausesForHuman: null`, which meant nothing downstream could
  tell the run would park and wait for a person rather than fail; and every
  attribute-discovered kind reported no output shape, forcing a host to keep a
  parallel output-shape table beside the executor. Two definitions of one fact
  is the drift co-located discovery exists to prevent
  (fancy-flow-php#14).

  **Nothing to do on upgrade** — every new parameter is optional and named, and
  a kind that declares none behaves exactly as before.

  A closure cannot live in a PHP attribute, so a config-dependent shape is
  declared with the existing marker, which `NodeKind::fromArray()` turns back
  into a closure yielding null:

  ```php
  #[FlowNode(
      name: '@acme/approve',
      pausesForHuman: 'approval',
      outputShape: NodeKind::DYNAMIC_OUTPUT_SHAPE,
      emits: 'input',
  )]
  ```

  Kinds declared in config keep passing real closures; nothing there narrows.
  `outputShape: []` remains the positive claim "emits no fields", distinct from
  omitting it, and the attribute preserves that distinction rather than
  collapsing empty into absent.

---

## 0.49.1 — 2026-08-27

### Security

- **Resolved node inputs are now recursively redacted and size-bounded before
  persistence.** Common password, secret, credential, authorization, cookie,
  private-key, API-key and access/refresh-token keys are replaced with
  `[REDACTED]` at any depth. The inspectable copy is capped at 256 KiB by
  default (`persistence.recorded_input_max_bytes`) with visible truncation
  markers, while the executor continues to receive the original untouched
  inputs. This prevents the new 0.49 execution record from turning credentials
  or unbounded payloads into durable run history.

---

## 0.49.0 — 2026-08-27

### Added

- **Durable per-node records now include the exact inputs the executor
  received.** `WorkflowRunNode::$inputs` is captured from the engine-created
  `ExecutionContext` before the executor starts, beside the row's existing
  output, status, activated ports, attempts, error and timestamps. An admin run
  debugger can therefore show the live values that actually passed through a
  node without recreating Fancy Flow's port-resolution rules in the host.

  Failed and paused nodes retain their delivered inputs; retries expose the
  latest attempt. Skipped nodes and old checkpoints remain `null` rather than
  presenting inferred history as fact. Consumers using the `per_node` driver
  should run the new published migration; no application code change is
  required to keep executing workflows.

---

## 0.48.1 — 2026-08-26

### Fixed

- **0.48.0 called a swimlane a floating node.** The float rule exempted `note`
  and nothing else, so any node that legitimately never gets wired was refused:

  - a **host kind whose category is `annotation`** — someone else's note;
  - a **`layout` kind**, which is what a swimlane is. The TS runtime ships
    `@particle-academy/lane` and its engine walks straight past it;
  - **a kind this registry has never heard of.**

  The last one is the one that bit. PHP's registry has no `lane`, so a graph
  authored in the TS editor with swimlanes arrives here as unknown kinds — and
  every lane in it collected a "connected to nothing" error UNDERNEATH the
  unknown-kind error it already had. A second, misleading message about a node
  whose actual problem is that this runtime does not know the kind.

  An unknown kind might be a step, an annotation or a lane. Claiming it must be
  wired asserts something that cannot be checked, so it no longer does; the
  unknown-kind issue still fires and is the accurate one.

  Found by porting the rule to the TS runtime, which is where the `layout`
  category and `@particle-academy/lane` are visible. Reading the PHP code alone
  would not have surfaced it.

---

## 0.48.0 — 2026-08-26

### Added

- **A graph containing a node that cannot take part in it is now an import
  ERROR.** Two shapes, both of which previously imported clean and then quietly
  did nothing. Both were measured against the engine before the check was
  written — neither of them FAILS, which is precisely why they are worth
  refusing:

  - **A floating node** — no inbound edge and no outbound edge. It is not
    skipped: a node with no incoming edge is a root, so the topo sort runs it.
    A three-node graph with one stray `log` executed `t,lonely,o`. It runs
    disconnected, receiving nothing from the graph and reaching nobody in it.
  - **An edge whose SOURCE is a terminal node** (`output`, `log` — the kinds
    declaring an empty output-port list). Measured: `t -> output -> log`
    imported clean and the `log` ran, with `{{ input }}` resolving to `""`.
    Nothing errored, nothing warned, and the downstream node operated on a hole.

  `note` is the one kind allowed to float, matched across every id it answers
  to (`note` and `@particle-academy/note`). That is less an exception than the
  definition of it: an annotation is a comment on the canvas, the engine never
  executes it, and requiring it to be wired would make it a node.

  Errors rather than warnings because both are unambiguous. No data at run time
  makes a floating node participate, and none makes an edge out of a terminator
  deliver — which is the test for refusing at authoring time instead of warning.

  New: `FancyFlow\Analysis\GraphConnectivity`, called from `Workflow::import()`
  and therefore from `fancy-flow-mcp`'s `validate_workflow` and `run_workflow`.

### What a consumer must do

**Almost certainly nothing, and NO stored workflow stops running.** Every run
path — `FancyFlowManager::toGraph()`, `EloquentWorkflowResolver`,
`SubgraphExecutor` — takes `ImportResult->graph` and does not read `ok`. This
refuses at AUTHORING time; it does not gate execution of a graph already saved.

Where you WILL see it, and it is the point:

- `php artisan flow:validate` and `flow:run` (both check `ok`) now fail a graph
  they used to accept.
- `fancy-flow-mcp`'s `run_workflow` refuses to run one, and `validate_workflow`
  reports it. Mid-build drafts are unaffected — `validate` runs only when the
  agent calls it, never after each `add_node`.

If you have a saved graph with a stray node, delete it or wire it; if it was a
comment, make it a `note`. The error names the node id (or the edge id, for a
terminator edge) so an editor can highlight exactly the thing to fix, and every
offending node is reported at once rather than one per round trip.

### Not yet ported

This lands in the PHP runtime only. `fancy-flow` (TS), `fancy-flow-py` and
`fancy-flow-rs` still accept both shapes, so an editor on the TS runtime will
let you draw an edge this validator then refuses. Saying so here rather than
leaving the four runtimes silently disagreeing.

---

## 0.47.0 — 2026-08-26

### Added

- **A `branch` or `switch_case` that routed on a path resolving to NOTHING now
  says so.** Previously it just went the wrong way.

  `branch` resolves its `condition` and asks `Expr::truthy()`. An unresolvable
  path yields `null`, `null` is falsy, and the run takes the **`false`** port —
  silently, and for a reason that has nothing to do with the data.
  `switch_case` does the same one step over: a `value` that does not resolve
  becomes `''`, matches no case, and falls to `default`.

  From the outside that is indistinguishable from a condition that was
  legitimately false. It is the `''` collapse again, except it changes the
  ROUTE rather than the text — which is worse, because an empty string is at
  least visible in the output. Half the graph never runs and the run reports
  success.

  ```
  Node triage took the "false" port because `condition` resolved to NOTHING —
  the path in.urgent names no field on this node's inputs. That is not the same
  as a false condition: the route was decided by an absent value rather than by
  the data.
  ```

  **Routing is UNCHANGED and deliberately so.** An unresolved condition still
  takes `false`; altering that would silently re-route graphs that have been
  running for months. What was missing was the reason, and that is what this
  adds. A host that would rather fail outright has `UnresolvedPolicy::Throw` on
  `Expr::evaluate()`.

  **It does not fire on an honest `false`.** Not for `false`, `0`, `''`,
  `'false'`, `'0'`, `[]`, or a resolved `null` — the key existing is what
  matters. Not for a condition that mixes text with an expression, which is
  being used as a string. Not for the `{{a}}{{b}}` corner, which is a malformed
  template rather than an absent field. Most branches ever run take `false`
  honestly, and a warning on those is noise — which is how a real warning stops
  being read.

  **Do you have to do anything?** No. If a warning appears, a routing decision
  in your graph is being made by an absent value, and it was already happening
  silently.

  Found by **flabs**: an agent built a correct triage graph whose urgency check
  referenced a field that did not resolve, so a request reporting total payment
  failure was routed as non-urgent. The graph was right, the path was wrong, and
  nothing said so.

## 0.46.0 — 2026-08-26

### Fixed

- **The authoring API and the engine disagreed about which ports a node has, so
  an agent that did exactly what it was told was marked wrong.**

  Three kinds decide their ports from their own config: `switch_case` publishes
  one port per entry in its `cases` map, `llm_router` one per declared route,
  and `subflow` gains `stream` in the streaming modes. Their `NodeKind` can only
  carry a representative default.

  That answer was computed in **two places that did not agree**.
  `fancy-flow-mcp`'s `PortResolver` derived it from config — so
  `describe_node_kind` correctly told an authoring agent that a third case
  existed once three were configured — while the ENGINE read only the kind's
  static declaration, and 0.44.0's undelivered-edge warning therefore reported
  that same port as impossible.

  The pairing is the worst available: **the authoring API invited the edge and
  the runtime then called it a mistake.**

  There is now one rule, `Registry\PortResolution`, in the engine. It is public,
  so `fancy-flow-mcp` calls it instead of keeping a second copy — two copies of
  one rule agree right up until someone edits one of them, and nothing anywhere
  reports the divergence.

  **Do you have to do anything?** No. If you were seeing spurious
  undelivered-edge warnings on a configured `switch_case`, `llm_router` or a
  streaming `subflow`, they stop. The warning still fires for a handle no
  configuration could produce — a `case_z` that is in neither the cases map nor
  the declaration is still a mistake, and there is a test pinning that the fix
  did not widen into uselessness.

  Found by **flabs**: an agent configured a third case through the MCP, which
  offered it, and the engine failed the graph its own authoring tools had led
  the agent to build.

## 0.45.0 — 2026-08-26

### Fixed

- **0.43.0's host-kind fix did not work in a Laravel app — which is the only
  place its reporter runs.** If you took 0.43.0 or 0.44.0 for that fix, take
  this one.

  0.43.0 taught `FlowRunner` to resolve a node's output ports through
  `$executors->kinds()` rather than the static `NodeKindRegistry::default()`.
  That was correct and it was tested. **But `FancyFlowServiceProvider` built the
  container's `ExecutorRegistry` without passing the container's
  `NodeKindRegistry`** — so `kinds()` fell straight back to the static builtin
  catalogue, your kinds were invisible again, and a host kind with declared
  ports published a single `out`.

  Every consequence from 0.43.0's entry therefore still applied in a Laravel
  app: an edge leaving one of your kind's real ports bound nothing, **with no
  failure and no warning**, and the downstream template rendered empty while
  being completely correct.

  It also produced a false positive in 0.44.0's new undelivered-edge warning.
  A known kind arriving as unknown falls back to `['out']`, so every named port
  looked impossible and **ordinary branching through a host kind warned** —
  a `pass`/`fail` node that took `fail` was reported as misconfigured.

  **Why the existing test could not see it.** `HostKindPortsTest` constructs
  `new ExecutorRegistry(kinds: $hostKinds)` itself, and that path was always
  correct. Nobody constructs an `ExecutorRegistry` in an application; they
  resolve one from the container. **A suite that builds its own objects cannot
  catch a defect in how the framework builds them** — the same shape as the
  `tool_calls` contract gap in 0.42.0, from the other direction. There is now a
  Laravel-layer test that resolves everything from the container.

  **Do you have to do anything?** No. If you register kinds into the container's
  `NodeKindRegistry` — which is what the docs have always said — they now route.
  If you worked around this by declaring `outputs` on every node in your
  documents, you can stop; node-level `outputs` still win, so leaving them costs
  nothing either.

  `Builtin::executors()` takes an optional third argument, the kind registry, for
  hosts that build their own.

  Found by **flabs** on its first end-to-end run against a real Laravel app —
  the failure the unit suite structurally could not reach.

## 0.44.0 — 2026-08-26

### Added

- **An edge that delivers NOTHING now says so.** The engine emits a `warn` log
  event naming the edge, what it would have delivered, and what to do.

  `collectInputs` binds a payload only when `"<sourceId>:<handle>"` exists, and
  the miss was silent in both of its shapes: if the bad edge is a node's only
  inbound one the node is skipped; if the node has another live edge it RUNS
  with that port simply missing — and then the downstream template is
  **completely correct and renders empty**, because the payload never arrived to
  have a field in it. A consumer misdiagnosed two filed issues off the back of
  the second shape, and an agent "fixed" one by correcting a field name that was
  never wrong.

  ```
  Edge e2 reads port "text" from node n2, which never publishes it — nothing
  would reach n3 at run time. Available: out. Note: "text" is a FIELD this node
  emits, not a port — read it downstream as {{ in.text }} rather than naming it
  as a source handle. Leave sourceHandle off to read the node's output.
  ```

  The near-miss clause is the part only the engine can supply, and it names the
  actual confusion: an agent reaching for a field name where a port belongs. The
  event carries `detail` (`edge`, `source`, `sourceHandle`) so a host can wire it
  into its own diagnostics without parsing the sentence back apart.

  **It does NOT warn on ordinary branching.** A `branch` that took `true`
  publishes no `false`, and the edge leaving `false` binds nothing — that is
  normal. The test is whether the handle is a port the node *could ever* publish,
  not whether it did; asking "did it publish?" cannot tell the two apart, and
  would warn on every branching graph. A warning that fires on ordinary
  branching is noise, and noise is how a real warning stops being read.

  Message shape agreed with the consumer who reported the defect.

### Changed

- **Upgrading from before 0.43.0? `for_each` is the one builtin to check.**

  0.43.0's notes said "a node whose kind declares ports and does not declare its
  own", which is correct and sends you to audit every kind. MOIC did the audit
  and the answer is a single name, so here it is directly.

  Of the builtins declaring named ports without an `out`, four — `branch`,
  `switch_case`, `human_approval`, `llm_router` — return `Port::branch` /
  `Port::only`, which `activatedPorts` handles *before* the declared-ports
  fallback. Their routing never depended on the thing that was broken.

  **`for_each` is the exception.** `ForEachExecutor` returns a plain
  `['items' => …, 'count' => …]`, so it falls through to the declared ports:
  before 0.43.0 it published `out`, and now it publishes `item` and `done` —
  which is what its own docblock has always claimed it does, and what the Node
  runtime has always done for the same JSON. **A handle-less edge leaving a
  `for_each` therefore stops delivering.** Give it `item` or `done`.

  If you have your own kind that declares named ports and returns a plain value,
  the general rule still catches it — and as of this release the engine warns
  about it at run time rather than leaving you to find it.

## 0.43.0 — 2026-08-26

### Fixed

- **A HOST-registered kind's output ports were invisible to the engine, so
  edges leaving them delivered NOTHING.** `FlowRunner::activatedPorts` resolved
  kind ports through `NodeKindRegistry::default()` — the shared static
  catalogue — instead of the registry the host supplied to its
  `ExecutorRegistry`. A kind your application registered fell through to
  publishing a single `out`.

  That is worse than "some ports are missing". `collectInputs` binds a payload
  only when `"<sourceId>:<handle>"` exists, so an edge leaving your kind's real
  port found nothing and delivered nothing — **no failure, no warning**. The
  downstream template is then completely correct and renders empty, because the
  payload never arrived to have a field in it. The consumer who reported it
  misdiagnosed two filed issues off the back of exactly that, and an agent
  "fixed" one by correcting a field name that was never wrong.

  It also broke this package's central guarantee: the TS side resolves ports
  through the registry a host registers into, so the **same graph JSON routed
  correctly on Node and collapsed to `out` on PHP** — for precisely the hosts
  that extend the kit.

  **Do you have to do anything?** If you pass your own `NodeKindRegistry` to
  `ExecutorRegistry` (`new ExecutorRegistry(kinds: $yours)`), nothing — it now
  works as it always read. If you were working around this by declaring
  `outputs` on every node in the document, you can stop; node-level `outputs`
  still take precedence, so leaving them costs nothing either.

  `ExecutorRegistry::kinds()` is new and public, for hosts that need the
  effective catalogue.

  Reported by MOIC.

- **`NodeKindRegistry::default()` was an EMPTY singleton nothing ever
  populated**, which is why the fallback above had never resolved a kind in the
  first place.

  It was `self::$default ??= new self()`; `Builtin::register()` is called on
  fresh registries in three other places and never on this one. So the kind-ports
  fallback — added, in its own comment, so that a branch node would not "collapse
  to a single `out` here while routing correctly on Node" — **could never fire.**
  The guarantee was broken by the very lookup written to uphold it.

  Wired to nothing: present, reviewed, commented, and with no effect. The
  TypeScript twin had the identical defect and was fixed the same day with
  `ensureBuiltinKinds()`; nobody checked the PHP side until MOIC's report led
  here.

  **Do you have to do anything?** Almost certainly not, and it is worth being
  precise about the one case. A node whose kind declares ports, that does NOT
  declare `outputs` itself, previously published `out` and now publishes its
  kind's ports — which is what the Node runtime has always done for the same
  JSON. If you have an edge with no `sourceHandle` leaving such a node, it reads
  `out` and would now find nothing; give it the real handle. All 484 tests here,
  including every golden parity fixture, pass unchanged.

### Added

- **`Expr::tryResolvePath()` — telling "did not resolve" apart from "resolved to
  empty".**

  `resolvePath()` returns `null` both for a path that does not exist and for one
  that exists holding `null`, and at the interpolation layer that collapses
  further to `''`. In the reporting consumer's words: *"An unresolvable path
  yields `''`, so a wrong field is indistinguishable from an empty one at
  runtime."* A misspelled field renders as an empty string, which looks exactly
  like a field that is legitimately empty — worst on LLM-authored graphs, where
  the field name was guessed to begin with.

  Same shape as the four `??` collapses fixed across all four runtimes earlier
  today, one layer up. A second return channel rather than a cleverer sentinel,
  because **every sentinel is a legal value for somebody**: `''`, `null` and
  `false` are all things a real payload carries.

- **`Expr::evaluate(..., UnresolvedPolicy $onUnresolved)`** — `Empty` (today's
  behaviour, the DEFAULT), `Keep` (leave the `{{ … }}` text so the failure is
  visible in the output without stopping the run), `Throw` (refuse, with the
  path on the exception).

  **Do you have to do anything? No.** The default is unchanged and every
  existing call site keeps its behaviour. Opt-in before default was the
  reporting consumer's own condition. Implemented identically in all of
  `fancy-flow`, `fancy-flow-py` and here.

### Fixed

- **`Expr`'s object branch treated a declared property holding `null` as
  absent.** It tested `isset()`, which is false for `?string $x = null` —
  `isset()` IS the absent-vs-null collapse this release exists to remove, so
  relying on it would have reproduced the bug inside the fix.

  Now `isset()` first, falling back to `property_exists()`. That order matters:
  `property_exists()` does not consult `__isset`/`__get`, and an Eloquent model's
  attributes are all magic — testing it first would have broken every host that
  puts a model in the context. No `resolvePath()` answer changes; only
  `tryResolvePath()` can observe the difference.

## 0.42.0 — 2026-08-26

### Fixed

- **The `agent` node's tool loop could never engage with anything we ship.**
  `AgentExecutor` reads `$response['tool_calls']`, invokes each tool and calls
  the model again — but that key was **absent from the `LlmClient` contract**,
  and the only shipped implementation (`EchoLlmClient`) never returned it.

  So an adapter written to the contract emitted no tool calls, the loop returned
  after one step, and the `agent` node degraded to a single completion — which
  looks exactly like a model that chose not to use a tool. The degraded
  behaviour is indistinguishable from a legitimate outcome, which is why it
  stayed invisible.

  **Do you have to do anything?** Only if you wrote your own `LlmClient`. It now
  MAY return `tool_calls`, and if your provider supports them it should — each
  entry `{name, arguments, id?}`. Cap the provider at ONE step when you do:
  **this executor owns the loop**, and letting the provider run its own as well
  invokes every tool twice and hides half the trace from your audit. Existing
  clients that return none keep working exactly as before.

  **The loop now has tests — its first.** No test here could have caught this,
  because every fake in the package was built from the contract that omitted the
  key: a suite cannot catch a contract gap using only fakes written to that
  contract.

  Reported by the Prism harness while reviewing v0.41.0 for integration.

### Changed

- **`particle-academy/prism` was pinned to `0.111` EXACTLY** — no range at all,
  so it could not take a patch, let alone three minors of fixes. Now
  `>=0.114 <1.0`.

  What arrives with it: the **Perplexity Agent API** move (the provider posts to
  `/v1/agent`; Sonar's `/chat/completions` retires 2026-09-27), a Thread contract
  letting stored conversations supply history, real cost reported into
  `Meta::$cost`, and a fix for a prompt of exactly `"0"` being silently dropped.

  Nothing for you to do — widening only ADDS candidates, so whatever you have
  installed still resolves. A caret would have been wrong here for the usual
  reason: on a `0.x` it locks the MINOR, which is how the pin got three behind.

## 0.41.0 — 2026-08-26

### Added

- **`emits: 'input-map-merged'` — merging the input MAP is not merging the
  payloads**, and one keyword was covering both.

  `manual_trigger` and `schedule_trigger` merge the raw input map; `merge` unions
  each port's PAYLOAD. Those coincide only at an entry point, because
  `collectInputs` seeds an entry node FLAT and keys every other node by handle —
  so `$ctx->inputs` is the payload at an entry point and a port-keyed map
  everywhere else.

  Give a `schedule_trigger` an inbound edge (a subflow where the trigger is also
  a target) and it emits `{cron, timezone, in: {…}}`. The single keyword
  over-permitted `{{ in.<upstream field> }}` when the real path is
  `{{ in.in.<field> }}`.

  **Do you have to do anything?** No. This is a DECLARATION about existing
  behaviour — no node changed what it emits. It matters if you read `emits` to
  decide whether a reference resolves: two names because they are two
  operations, rather than one name plus a positional rule the reader has to know.

  Named by the reference consumer, who found the boundary by reading
  `$ctx->inputs` in both shapes rather than by reading the relation description.

## 0.40.0 — 2026-08-26

### Fixed

- **A port bound to NULL was treated as an ABSENT port.** `ExecutionContext::input()`
  read `$this->inputs[$port] ?? $default`, so a port holding an explicit `null`
  returned the DEFAULT instead of `null`.

  Eleven executors call `input('in', $ctx->inputs)` — whose default is *the whole
  inputs map*. So a null `in` did not yield null, it yielded **every input the
  node had**. That is worse than the wrapper leak fixed in 0.39.0, and for a
  specific reason: the wrapper was visibly odd, while an inputs map is
  PLAUSIBLE. It looks exactly like real data, so a downstream node reads fields
  from the wrong place and nothing looks wrong anywhere.

  The fallback itself is right and stays: a trigger has no `in` edge, and "the
  `in` port, or everything if there is no `in` port" is what lets an entry node
  read its seeded payload. Only the ABSENT case may fall back now —
  `array_key_exists`, which is the only correct test.

  **Do you have to do anything?** Only if a node of yours was relying on a null
  input silently becoming the full inputs map — which was never intended and is
  hard to depend on deliberately. A node that emits null now delivers null.

  Third layer of one collapse: `input()`, `activatedPorts`, and the regression
  test written for `activatedPorts` — which asserted with `?? '__absent__'` and
  so could not see the null it was testing for. **The rule: `??` (and `is None`,
  and `unwrap_or`) is safe only where null is not a legal value.** Fixed in all
  four runtimes.

  Found by the consumer asking whether the CAUSE had been fixed or only the
  symptom.

## 0.39.0 — 2026-08-26

### Fixed

- **A branching node whose payload is NULL emitted the wrapper downstream.**
  `activatedPorts` unwrapped the two port sugars asymmetrically:

  ```php
  Port::only   ['__port' => …]  ->  $result['value'] ?? null
  Port::branch ['branch' => …]  ->  $result['value'] ?? $result
  ```

  `??` collapses two different questions — *is there a `value` key* and *is the
  value null*. With no key the whole result IS the payload, which is what the
  fallback exists for; with a key holding null the payload is null. Collapsed,
  `Port::branch($port, null)` sent every downstream node
  `['branch' => 'x', 'value' => null]` — two fields no kind declares, while the
  fields it does declare were absent.

  Now `array_key_exists`, which keeps the fallback and answers the right
  question. **All four runtimes shared this identically**, so no parity table
  could have caught it: they agreed on being wrong, and a parity suite only sees
  disagreement.

  **Reachability, stated precisely because the first account was wrong.** It is
  NOT reachable through `$ctx->input('in', $ctx->inputs)`, which is itself
  `$this->inputs[$port] ?? $default` — a null `in` returns the DEFAULT, so the
  built-in `branch`, `switch_case` and `human_approval` executors never hand
  down a literal null. The same collapse one layer up masks it. It is reachable
  from a HOST executor returning `Port::branch($port, null)` directly, which is
  what the regression test does.

  Found by the reference consumer reviewing something else, by reading the
  CONSUMER of a return value rather than the return.

## 0.38.0 — 2026-08-26

### Added

- **Runs the shared `flow/kind-declaration-surface` table.** 20 cases asserting
  that this runtime declares the same things about the same kinds as the other
  three — field SETS and `emits` relations, not behaviour.

  Every other conformance table here pins what the engine DOES. Nothing pinned
  what a kind DECLARES, and four capabilities were found present in one runtime
  and absent in the others as a result. This is what makes a fifth loud.

### Fixed

- **`fancy-conformance` was pinned at `^0.13.0` and had been frozen six minors
  behind.** A caret on a `0.x` admits only that minor, so `composer update`
  reported success while installing nothing — and every shared table added since
  0.13.0 reached this runtime never.

  That is the failure this package's own tables exist to catch, in the
  dependency that delivers them: a check that cannot receive new rows is a check
  that slowly stops checking, and nothing reports it because the suite stays
  green on the rows it already had.

  Now `^0.19.0`. **A conformance dependency has to track**, unlike a normal dev
  dependency where a caret pin is deliberately the version the suite was built
  against — the whole point of this one is that new shared rows arrive and fail
  whichever runtime drifted.

## 0.37.0 — 2026-08-26

### Added

- **`emits` — how a kind's output RELATES to its input.** The half a field list
  cannot express, and the reason a consumer was reimplementing our executors'
  semantics in a static analyser.

  `outputShape` answers *which fields*; `emits` answers *where they come from*.
  Separate fields because they are separate questions — one field carrying
  sometimes-a-list-sometimes-a-keyword is one a reader handles only in the form
  it met first.

  | value | meaning |
  |---|---|
  | `'input'` | emits its input unchanged |
  | `'inputs-merged'` | the union of every input's fields |
  | `'expression:<key>'` | the shape the expression in THAT config key names |
  | a `Closure` | the relation itself depends on config |

  Read it through `emitsFor($config)`; `expressionConfigKey($config)` returns
  the key an expression relation names.

  **The key is part of the value on purpose.** `transform` reads
  `config.expression`, `variable` reads `config.value`. A consumer hardcoding
  "the field called expression" has copied our knowledge one level down, which
  is the thing this removes.

  Now declared: `branch`, `switch_case`, `output`, `human_approval`,
  `manual_trigger` (`input`); `variable` (`expression:value`); `transform` and
  `merge` (Closures — `transform` passes through when no expression is set,
  `merge` has no relation in `concat` mode); `schedule_trigger`
  (`inputs-merged`, composed with its own `cron`/`timezone` list).

### Two traps this design walked into, both caught before shipping

- **A relation with no destination can only express a TOP-LEVEL merge.** `wait`
  returns `['waited' => …, 'duration' => …, 'input' => …]` — it **nests** its
  input under a key. `emits: 'input'` there would make a reader accept
  `{{ in.<any inbound field> }}` at top level, which resolves to nothing at run
  time. `wait` therefore keeps a static list with an opaque `input` field and no
  relation. **Read the executor and ask *merge or nest* before assigning one.**

- **`merge` in `concat` mode declares `null`, not `[]`.** It builds a list whose
  elements are not addressable as top-level fields. `[]` would claim "emits no
  fields" — false, and it would refuse every reference. Under-claiming is free.

  `schedule_trigger` moved OUT of the deliberately-undeclared set for the
  opposite reason: a partial `['cron','timezone']` list was unsafe only while
  nothing could say the inputs also merge. With the relation declared beside it
  the two are complete together.

  The design, and both corrections, came from the reference consumer.

## 0.36.0 — 2026-08-26

### Fixed

- **`agent` was missing `truncated`, so a validator refused a real field.**
  `AgentExecutor` has TWO returns: `:52` on the normal path and `:64` on the
  max-steps path, which adds `truncated`. The declaration cited only `:52`.

  A consumer checking references against the declaration therefore **refused
  `{{ in.truncated }}`** — a field that genuinely exists, on the path an author
  is most likely to be debugging, and a false rejection is one the author cannot
  comply with.

  **The method needed one more line: read EVERY return, not the top one.**
  `grep -n "return \[" <executor>` before writing a row. Every other declared
  kind was re-swept the same way; `llm_router`'s second return is an empty early
  bail and adds no fields, and the rest have exactly one. This was the only
  incomplete row.

  `truncated` appears on one path only — the variants case arriving on its own.
  Declared flat until a shape can express that: over-permitting it on the normal
  path costs nothing, omitting it refuses a valid reference.

  Caught within minutes of release by a consumer's two-way divergence test,
  which also found the matching error in their own table.

## 0.35.0 — 2026-08-26

### Added

- **`api_request` declares `status` / `headers` / `body`**, from the
  `HttpClient` contract at `Nodes/Support/HttpClient.php:16` —
  `array{status:int,headers:array<string,mixed>,body:mixed}` — which
  `ApiRequestExecutor.php:31` returns unchanged.

  Found by comparing the two runtimes rather than by reading either alone: the
  TypeScript twin had declared this kind and PHP had not, so a PHP host could
  not check `{{ in.status }}` on the one kind whose shape both runtimes already
  agreed on. **Eleven kinds now declare here; ten in TypeScript** — the
  difference is `agent`, which TypeScript has no kind for.

## 0.34.0 — 2026-08-26

### Added

- **Ten builtin kinds now declare what they emit.** Eight static —
  `embed_search`, `llm_router`, `notify`, `webhook_out`, `for_each`, `wait`,
  `log`, `agent` — plus two config-dependent Closures, `llm_call` and
  `user_input`.

  Every declaration was read from its EXECUTOR's return statement, with the file
  and line cited beside it. **None was copied from the TypeScript twin or from
  any other declaration**: the two errors found in a consumer's hand-maintained
  table were both rows that agreed with a second artefact, so "two declarations
  agree" is not evidence of either being right.

  `llm_call` gains `data` **only when the author set a `response_schema`**
  (`LlmCallExecutor.php:89`); the rest is the client contract at
  `Nodes/Support/LlmClient.php:28`. `user_input` emits the keys its author
  defined. Both are therefore Closures, and both report
  `hasDynamicOutputShape()`.

### Known limitation — read this if you build a registry from a manifest

- **`llm_call` and `user_input` lose shape checking through a JSON manifest.** A
  Closure cannot be serialised; `toArray()` writes `"dynamic"` and a restored
  registry answers `null` — correctly meaning *"a shape exists and this process
  cannot resolve it"*, distinguishable via `hasDynamicOutputShape()`.

  Saying it plainly because "the builtins now declare their shapes" would
  otherwise read as *checking works everywhere*, and for manifest-backed
  consumers it is the opposite on the most-referenced kind there is. A
  code-registered registry keeps full precision.

  A serialisable conservative floor was considered and rejected: derived from a
  kind's `defaultConfig` it is only sound if that config's output is a subset of
  every other config's, which nothing enforces — a "floor" that is not a floor
  makes a validator refuse a valid reference, which is worse than declining to
  answer. Raised by the reference consumer.

### Deliberately still undeclared

- `branch`, `switch_case`, `output`, `transform`, `merge`, `manual_trigger`,
  `webhook_trigger`, `human_approval`, `variable` and `schedule_trigger` remain
  `null`, and that is the honest answer: they emit what arrived, so their shape
  is not knowable from the kind alone.

  `schedule_trigger` is the sharp case — it `array_merge`s its inputs into the
  TOP level (`ScheduleTriggerExecutor.php:23-28`), so a partial static list of
  `['cron','timezone']` would make a validator **refuse every merged-in key**. A
  partial static list on a merging kind is a false-rejection generator, and a
  false rejection is one an author cannot comply with.

  Declaring the RELATION these kinds have to their input — rather than a field
  list — is the next piece of work.

## 0.33.0 — 2026-08-26

### Added

- **`outputShape` — the FIELDS a kind emits, not its ports.** It existed in the
  TypeScript twin and in neither backend, so a host running on PHP had
  nothing to check `{{ in.field }}` against.

  The consequence was not theoretical. A design partner hand-maintained a table
  of emissions derived by reading our executors' source, because the runtime
  their graphs execute in had nowhere to declare it. That table drifted: it
  **refused a legitimate `{{ in.title }}` while accepting a field that does not
  exist** — a false rejection an author cannot comply with.

  Three states, and the third is the point:

  | | means |
  |---|---|
  | `null` | NOT DECLARED — nobody has said |
  | `[]` | declares that it emits no fields |
  | a list | `[{ path: "text", type: "string" }, …]` |

  Collapsing "not declared" into "declares nothing" is the bug. It is the same
  shape as `graph.inputs` dropped on import and `sideEffects` declared by
  nothing: **a capability present in one runtime and absent in the others, where
  absent reads as a legitimate answer.**

  **A Closure of config is a first-class form**, not an escape hatch: a
  `user_input` emits the keys its author defined and a `system_event` its
  event's payload, and no static list knows either. Read it through
  `outputShapeFor($config)` rather than the property directly, so both forms resolve
  identically and a caller cannot handle only the one it met first.

  Serialising a dynamic shape writes `"dynamic"` rather than omitting it —
  omission would say "no outputShape", which reads as "emits nothing" and would
  reintroduce the exact failure at the serialisation seam. It comes back as a
  Closure yielding null: *a shape exists, and this process cannot resolve it.*

  **What a consumer must DO:** nothing. Purely additive — every existing kind
  reads `null`, which is the honest answer for a kind that has never declared
  one. Populating the builtins follows.

## 0.32.0 — 2026-08-25

### Fixed

- **`sideEffects` was declared by NOTHING, so the retry protection built on it
  could never engage.** Zero of the 26 builtin kinds set it, so
  `NodeRetryPolicy::isUnsafeToReplay()` returned `false` for every node in
  existence and the one-attempt pin was unreachable.

  The docs promise otherwise **twice**: `NodeRetryPolicy`'s own docblock says
  *"a node declaring `sideEffects: unsafe-to-replay` is pinned to ONE attempt"*
  and offers a PR-opening node filing twice as the motivating example, and
  `AGENTS.md` says *"per_node pins it to one attempt (never double-files)"*. The
  reading half shipped in 0.10; the declaring half never did.

  Same shape as `graph.inputs` being dropped on import — two of three links
  present and the chain silently dead. Reported by a consumer who **measured** it
  rather than reading it.

  **19 kinds now declare it.** `webhook_out` and `notify` are
  `unsafe-to-replay` — both deliver to somebody else, and a second attempt is a
  second delivery. `data_store`, `memory_store` and `variable` are `idempotent`
  (keyed writes). The pure logic, trigger, output and structural kinds are
  `none`.

  **Seven are deliberately left undeclared**, and a test pins that exact list so
  adding a kind and *forgetting* the field shows up rather than silently joining
  the set no retry rule can see:

  - `api_request` — the safety of a retry is the HTTP **method**, which is
    config, not kind. Declaring it unsafe would make every read-only call fail
    permanently, and `AGENTS.md` cites flaky HTTP as the *retryable* example.
  - `llm_call`, `llm_router` — a retry costs money and returns a different
    answer, but writes nothing external. Neither `idempotent` nor
    `unsafe-to-replay` is true.
  - `tool_use` — its effects are the host's tool.
  - `subflow` — its effects are the child graph's.
  - `user_input`, `human_approval` — these pause rather than fail; retry is not
    the axis they live on.

  Any of them can be pinned by a host that knows its own usage, via the per-kind
  `queue.node_tries` override.

  **What to do:** nothing, unless you were relying on `webhook_out` or `notify`
  retrying. They now get one attempt, which is what the documentation always
  said they got.

### Added

- A **liveness check** for the mechanism, which is the part that was actually
  missing. `SideEffectsAreDeclaredTest` asserts a real kind is genuinely pinned
  to one attempt, that an ordinary kind still takes its configured retries, that
  only the three vocabulary values are ever used (a typo is as dead as a null),
  and that the undeclared list is exactly the seven above.

  Verified by reverting all 19 declarations: the liveness test goes red against
  the state that shipped. Worth noting that the vocabulary check reported
  **"risky — no assertions"** in that state, because it iterates a set that was
  empty. A check that passes over nothing is the failure this whole class of bug
  is made of.

## 0.31.0 — 2026-08-25

### Added

- **`Workflow::migrate()` — a stored Op written against an OLDER schema now
  upgrades on read instead of being rejected.**

  The version has always been on the document. Only the TypeScript runtime acted
  on it — this runtime compared it and errored on any mismatch. So the day schema
  v2 was cut, **every stored Op would have hard-failed to import here**, and this
  is where durable runs RESUME: a run parked on a human approval would have
  become unresumable.

  That could only ever be fixed BEFORE the bump. Afterwards the graphs are
  already unreadable by the very code meant to migrate them.

  Three rules, each with a reason:

  - A **past** version migrates forward, step by step, to the current one.
  - A **future** version is left ALONE. We cannot know what a later schema means,
    and migrating downward would be guessing; untouched hands it to the version
    check, which reports it honestly.
  - A **gap** in the step table is left alone too. A missing step is not a
    licence to guess.

  **What to do: nothing.** The step table is empty because v1 is current, so
  every document passes through untouched and behaviour is identical to before.
  That is exactly the property that made it safe to add now rather than under
  pressure later.

  `migrate()` takes its step table as an argument, which is not decoration: with
  only v1 in existence there is no old document to migrate, so a seam tested
  against the built-in table would be a check that **cannot fail** — it would
  pass identically against a function that returned its input. Verified by
  reverting to exactly that and watching the forward-migration test go red.

  The Python twin ships the identical seam in `fancy-flow` 0.7.0, and the
  TypeScript one gained the same step-table shape in `@particle-academy/fancy-flow`
  0.56.0. One design, three runtimes.

## 0.30.0 — 2026-08-25

### Added

- **`maxConcurrent` — cap how many of a run's nodes are in flight at once, or
  run a graph serially** (fancy-flow-php#11). Per run, or as a deployment
  default:

  ```php
  FancyFlow::dispatch($flow, maxConcurrent: 1);   // one node at a time
  // or: FANCY_FLOW_MAX_CONCURRENT=1
  ```

  `AdvanceWorkflowJob` dispatched a job for **every** node in the ready frontier
  with no way to ask for fewer — `queue.drain_limit` goes the other way, letting
  one job drain *more* inline. Three things that costs, all reported from
  production: several `llm_call` nodes becoming ready together fire concurrently
  at the same provider; a fanning frontier makes *"what ran, in what order"*
  non-deterministic between runs of the **same** graph, which is the first
  question anyone asks of a failed run; and two nodes writing the same record are
  ordered only by luck.

  Worker topology (one worker on the queue) already achieves this, but it is a
  deployment-wide setting standing in for a per-run one, and it stops being true
  the moment anyone scales the worker for throughput.

  **The budget is measured against work already IN FLIGHT, not against the size
  of one batch**, and that distinction is the whole correctness argument. Two
  nodes settling at once each trigger an `AdvanceWorkflowJob`, so a per-batch cap
  would let each dispatch its own quota — the limit would hold on paper and not
  in production.

  Serialised runs are **deterministically ordered** by the graph's own node
  declaration, because `Frontier` walks `$graph->nodes` and `array_keys`
  preserves insertion. Bounding concurrency without fixing the order would answer
  the cost problem and leave the legibility one.

  A `PAUSED` node is deliberately **not** counted: a human gate holds no worker,
  so a run parked on an approval would otherwise consume its budget indefinitely
  and every parallel branch would stop dead behind a person. A limit of `0` is
  treated as unlimited rather than honoured — honouring it would deadlock a run
  with nothing able to start and nothing to re-trigger an advance, and a config
  typo should not be a way to hang a workflow forever.

  **What to do:** run `php artisan migrate` for the new nullable
  `max_concurrent` column. Unset is unlimited, which is exactly today's
  behaviour.

## 0.29.0 — 2026-08-25

### Fixed

- **Workflow props were unreachable from Laravel — in THREE places, not one**
  (fancy-flow-php#12). Props shipped in 0.25.0 and, on the path a Laravel app
  actually runs, could never carry a value. Reported as a bridge gap; it turned
  out the bridge was the last of three, and fixing only that one would have
  changed nothing observable.

  1. **`Workflow::import()` dropped `graph.inputs`.** That declaration is what
     `WorkflowProps::resolve` validates against, so every imported graph declared
     nothing and every prop was rejected with *"this workflow declares no
     inputs"*. A durable run always imports from the stored schema, so props
     could not work there by construction.
  2. **`Workflow::export()` never wrote `graph.inputs` back.** A graph designed
     in the TypeScript editor — which does emit them — passed through this
     runtime and came out silently undeclared.
  3. **Nothing in `src/Laravel` populated `RunOptions::$props`.** No column, no
     `RunSetup` accessor, and none of the three jobs passed it.

  The engine deliberately makes `$props` available to **every** node, so a value
  is not threaded edge by edge through nodes with no interest in it. Without
  this, the reporter's org-file payload could only be seeded into the trigger
  NODE and carried hop by hop — where a `user_input` answer landing on the same
  port replaces it wholesale and the content is gone from there on.

  ```php
  FancyFlow::dispatch($flow, props: ['content' => $file->text]);
  // any node, at any depth: {{ $props.content }}
  ```

  Validation is unchanged and still fails **before any node runs** — wiring the
  value through did not become a way to skip the check.

  **What to do:** run `php artisan migrate` for the new nullable `props` column.
  Behaviour for a run that passes none is identical to today.

### Changed

- `Workflow::export()` emits `graph.inputs` only when a graph declares some,
  matching the TypeScript exporter. An always-present `"inputs": []` would change
  the bytes of every graph ever saved, for nothing.

## 0.28.0 — 2026-08-25

### Added

- **`RunOptions::$entryNodes` — run only the trigger that actually fired**
  (fancy-flow-php#10). Names the live entry points; everything reachable only
  from the others is skipped.

  A graph may hold more than one trigger — a `manual_trigger` for hand-testing
  beside the event trigger that runs it for real — and a trigger has no inbound
  edges, which **is** the readiness rule. So every trigger's branch ran on every
  run, whichever one fired, under **both** queue drivers.

  The triggers themselves were harmless; everything downstream of the ones that
  did not fire was not. The reporter measured two failures: an empty payload
  winning a race into a shared `transform`, and — with no workaround — a
  `user_input` on the manual branch executing during an **event**-triggered run,
  parking the run to ask a person for data the event had already supplied. From
  outside, that reads as the event trigger being ignored. They were linting
  against the graph shape to avoid it.

  ```php
  FancyFlow::dispatch($flow, ['evt' => $payload], entryNodes: ['evt']);
  ```

  **What to do: nothing.** Unset is the default and behaves exactly as before —
  that compatibility guarantee is pinned as row `0101` of the shared table.

  Both schedulers are gated from one source of truth, because the per-node
  driver decides readiness itself in `Frontier::compute` and a gate in only the
  engine would dispatch a job for a node the engine then refuses to run.

  Two edges worth knowing, both pinned: **`null` is not `[]`** — unset runs every
  entry point, an empty list says none is live and runs nothing; and naming a
  node that HAS inbound edges names no entry point, so nothing runs. Validate
  your ids if you want a typo to be loud, because the runtime cannot distinguish
  one from a deliberate empty selection.

  Pinned by `flow/entry-points` in `particle-academy/fancy-conformance` (7 rows),
  written as a specification before any runtime implemented it. The TypeScript
  and Python twins satisfy the identical rows.

### Changed

- New migration: `entry_nodes` on `fancy_flow_workflow_runs`, **nullable**. Null
  means unset rather than none, so every run recorded before this column existed
  keeps behaving exactly as it did. A default of `[]` would have said "no entry
  point is live" and quietly stopped every historical run from resuming.

  Run `php artisan migrate` after upgrading. Nothing else changes.

## 0.27.0 — 2026-08-25

### Added

- **`Laravel\Events\NodeMessage` — a node's own words now reach Laravel**
  (fancy-flow-php#9). `(runId, nodeId, phase, message)`, dispatched from the same
  bridge as the other three, where `phase` is `'start'` or `'end'`.

  `startingMsg` / `stoppingMsg` have been emitted by `FlowRunner::announce()`
  since 0.25.0 as `RunEvent::nodeMessage()` — and `FancyFlowManager`'s bridge
  matched three event types and sent everything else to `default => null`. So a
  node's message to a person was computed, emitted, and **dropped one layer
  before any consumer could read it.**

  Worse than an absent feature: the docblock promises a channel deliberately
  separate from `nodeStatus`'s diagnostic `$text`, and a host building against
  that promise finds nothing arrives and gets no error explaining why. Reported
  by a consumer who had both surfaces — a chat feed and a tray pill — built and
  waiting for an event that could never arrive.

  **Verified on the DURABLE path specifically**, not just in-process. That was
  the reporter's actual path, and a fix confirmed only on the path nobody uses
  would be this same defect wearing a green tick. It works because
  `FancyFlowManager::run()` applies the bridge itself rather than leaving it to
  callers, so `RunNodeJob` → `GraphReplay` is wrapped too — the bridge *chains*
  a caller's `$onEvent` rather than replacing it.

  **What to do: nothing.** Purely additive. Listen for the event if you want the
  feed; ignore it and nothing changes.

## 0.26.0 — 2026-08-25

### Fixed

- **Binding one ordinary kind could silently install a GLOBAL FALLBACK for every
  unmatched node.** `bind()` expands a kind into every id it answers to, and
  already refused to expand the `*` sentinel *outwards* — but nothing stopped an
  alias expanding *inwards* to it. A kind whose alias list contains `*` therefore
  turned `bind('everything', …)` into `bind('*', …)`, and from then on every node
  with no executor of its own ran that one.

  Silent by construction: a fallback that exists and a fallback that does not
  both let the run complete. The `*` slot may now only be written by an explicit
  `bind('*')`.

  Found by `flow/executor-resolution/0107` the first time this side ran the new
  table — and the identical defect was in the Python twin, for the identical
  reason. Both expand aliases at BIND time; TypeScript was unaffected only
  because it expands at LOOKUP time and never looks the sentinel up as a kind.
  **One fixture row, two runtimes, one shared blind spot** — which is the case
  for the conformance package stated better than any argument for it.

  **What to do: nothing**, unless you registered a kind literally named `*`.

### Added

- **`flow/executor-resolution` runs on this side** — the `node id → kind → *`
  order, alias resolution in both directions, and failing closed when nothing
  matches. Eight rows run here; the six `0200` rows are skipped with a stated
  structural reason: this runtime's `FlowNode` is FLATTENED, so `$type` IS the
  kind and there is no `data` slot for a `data.kind` to disagree from.

  That asymmetry is the point rather than an omission. The TypeScript runtime
  could run the wrong executor when `type` and `data.kind` named different
  kinds; this one cannot, structurally. Inventing a `data.kind` field here so it
  could answer rows about one would be writing code to satisfy a table.

- `particle-academy/fancy-conformance` moved `^0.9.1` → `^0.12.0`.

## 0.25.0 — 2026-08-25

**Backfilled on 2026-08-25.** This entry was missing when the tag was pushed.
The release gate caught it and failed within seconds — detection worked; acting
on it did not. Recorded here rather than quietly added, because the gate's own
rationale is that a consumer reads this file to decide whether to take a
release, and an empty-looking one is read as an empty release.

### Added

- **Workflow props — the PHP twin, built against a published table.** A run can
  be given values for the inputs a graph DECLARES, by name, instead of by node
  id. `RunOptions::$props` carries them and `Runtime\WorkflowProps` resolves them
  against `FlowGraph::$inputs`.

  Keying by node id meant a caller had to know the trigger was called `t`, so
  renaming a node broke every caller while the graph stayed perfectly valid. A
  misspelled prop now FAILS the run before any node executes, rather than sitting
  unread — validation that happens after a side effect is not validation.

- `tests/Parity/WorkflowPropsConformanceTest.php` runs `flow/workflow-props` from
  the shared corpus. The table existed before this port was written, so it is a
  specification rather than a post-mortem — and it earned its keep at once,
  failing exactly one row that turned out to describe an input PHP cannot
  represent (`json_decode('{"0":"a"}', true)` coerces the numeric string key to
  an int, so the map becomes a list). That row is skipped with the reason
  attached; `0109` pins the same rule with a non-numeric key.

### Changed

- The release gate now checks changelog ORDER, not just presence.

## 0.24.0 — 2026-08-24

### Added

- **A node's inputs are now addressable by the SOURCE NODE'S ID**, alongside the
  port, whenever the edge declared no `targetHandle` (fancy-flow-php#8).

  ```
  {{ in.text }}    // still works, unchanged
  {{ n2.text }}    // now works too
  ```

  **The failure this closes is silence, not inconvenience.** Authors reach for
  node ids — it is how every graph tool addresses nodes, and it is the first
  thing an assistant generating a graph writes. That resolved to nothing, and
  *nothing failed*: an unresolvable path yields an empty string, so the node
  ran, the run reported success, and the damage was output that was quietly
  wrong. The reporting consumer shipped a `document.md` containing the literal
  text of its own template, on a green run, and found out when a human opened
  the file.

  `targetHandle` is unchanged and remains the mechanism for reading something
  other than the immediate predecessor. The model was never wrong — the obvious
  spelling just meant nothing.

  **Strictly additive.** The alias is written only for edges that named no
  handle (an edge that named one said what it meant), and never over a key
  already present from the host's initial inputs or an earlier edge. A dead
  branch contributes nothing, as before.

  **What you must do: nothing.**

---

## 0.23.0 — 2026-08-24

### Added

- **`FancyFlow\Testing\QueuePump` — drain a faked queue the way a worker
  would, and stop wherever you like.**

  Testing a durable run by starting a REAL worker introduces a race that has
  nothing to do with the code under test: a worker launched with
  `--stop-when-empty` can find the queue momentarily empty, exit having done
  nothing, and leave the assertion reading `running`. The gap widens with load,
  so it surfaces only in longer suites — it looks like flakiness and behaves
  like a threshold.

  A consumer measured exactly that, and their conclusion is the one worth
  carrying: **a test that fails only under load is usually measuring the
  harness.** They had carried two such failures as a known upstream bug for
  months; adopting this took them from 2-of-250 failing on three runs out of
  three to 250 passing, twice.

  This is stronger than a real worker rather than merely safer. `sync` always
  runs the advance → node → advance chain to completion, so *"the worker died
  here"* cannot be expressed at all; draining by hand and stopping after N node
  jobs means **stopping IS the kill**, and the run is left exactly as an
  interrupted worker would leave it — which is how you assert an abandoned
  frontier or a retry re-entering its own claim.

  It was already the technique this package's own per-node suite used, as a
  function inside a test file. Consumers were therefore being told to copy code
  out of our tests to exercise the durable layer's primary testing path. It now
  ships in `src/`, and the suite drives itself through the same class, so it
  cannot drift from the documentation.

---

## 0.22.0 — 2026-08-24

### Fixed

- **A pause wrote two rows with no transaction, and a crash between them was
  unrecoverable.** `RunNodeJob` settled the node claim as `PAUSED` and then, in
  a separate statement, parked the run (`awaiting_input` plus `awaiting_node` /
  `awaiting_kind` / `awaiting_detail`). A worker dying in between — OOM, a
  deploy, a dropped connection — left the node settled while the run still read
  `running`.

  That state has no way out. Nothing re-dispatches a settled node; a human
  answering is *rejected*, because the run is not parked on it. And unlike a
  COMPLETED node — whose activated ports the `Frontier` recomputes from the
  claim row — a paused node's consequences are not derivable: the `awaiting_*`
  fields exist only on the run, so losing that write loses the gate entirely.

  The pair is now one operation, `NodeClaims::pauseAndPark()`, inside a
  transaction — owned by the claim authority, which is where claim/run
  consistency belongs.

  Narrow window, permanent consequence, no detection: the exact profile the
  claim table exists to rule out, whose premise is that a lost race is a
  **no-op**.

  Found while chasing a consumer's report of runs stuck at `running` where
  `awaiting_input` was expected. **That report was retracted** — their case was
  worker latency under load, and they proved it by running the same tests on
  0.19.0 and 0.21.0 in isolation with identical results. But they declined to
  rule out a genuine pause bug, and taking that non-claim seriously found this
  one, which is a different defect that nothing had hit yet.

  **What you must do: nothing.**

---

## 0.21.0 — 2026-08-24

### Fixed

- **`subflow` now runs its child against the PARENT's registry** (#7). It fell
  back to `Builtin::executors($this->deps)` — the bare builtins — because
  `Builtin::executors()` constructs `new SubflowExecutor($deps)` while the
  composed registry is still being built, so there was nothing to hand it. The
  child therefore lost every host executor bound via
  `config('fancy-flow.executors')`, the `agent` binding, and the
  `ContainerResolver` the parent had.

  A host kind resolved at top level and vanished one level down. Worse, a host
  that had REPLACED a builtin — `llm_call` with its own tenancy, budgeting or
  token accounting — got the package's version inside the child: the same graph
  behaving two ways depending on nesting depth. Nothing warned, because an
  unregistered kind fails closed with no outputs.

  The registry now rides on `ExecutionContext::$executors`, so any executor
  that starts a nested run inherits it without opting in. An explicitly
  injected registry still wins; the bare builtins remain only as a last resort.

  Reported by a consumer running a real graph. The TypeScript and Python twins
  had the same defect — TS with a worse default, an EMPTY child registry — and
  are fixed in `@particle-academy/fancy-flow` 0.49.0 and `fancy-flow` (PyPI)
  0.2.0. Found by checking parity rather than assuming it, and now pinned by
  the shared `flow/subflow-registry` conformance table so it cannot drift back
  in one runtime.

  **What you must do: nothing**, unless you relied on a child having no
  executors — which was never a documented guarantee and could not be
  distinguished from this bug.

### Added

- **Per-node status messages.** A `FlowNode` may carry `startingMsg` /
  `stoppingMsg`; the runner announces them around that node as a new
  `RunEvent::nodeMessage()` (`node-message`, with a `phase` of `start` or
  `end`). Carried through `Workflow` import/export, and omitted from the
  document entirely when unset so a saved graph stays diffable.

  Opt-in per node — most nodes in a graph are plumbing, and narrating all of
  them buries the two or three steps a person actually follows.

  **`stoppingMsg` fires only when the node SUCCEEDS.** A completion message
  after a throw tells a human the opposite of what happened, in the part of the
  UI they trust most; failures continue to report through `node-status` and
  `log`. Skipped and resumed nodes stay silent too — neither did the work.

  Matches `@particle-academy/fancy-flow` 0.49.0 and `fancy-flow` (PyPI) 0.2.0.

---

## 0.20.0 — 2026-08-20

### Added

- **A node-level failure now says WHICH node failed.** `RunResult->error` and the
  emitted error events carry the node, and a new `NodeExecutionException` carries
  it as data rather than only in the string:

  ```
  node "Draft the summary" (n-42, @particle-academy/llm_call): StructuredOutput
  truncated — raise max_tokens or narrow the schema
  ```

  ```php
  catch (NodeExecutionException $e) {
      $e->nodeId;      // 'n-42'
      $e->nodeLabel;   // 'Draft the summary'
      $e->nodeKind;    // the kind that was executing
      $e->getPrevious(); // the original, untouched
  }
  ```

  Reported by a consumer running an Op with several `llm_call` nodes. The
  truncation message is a good one — it names the cause and says to raise
  `max_tokens` or narrow the schema — and it never said WHOSE `max_tokens`, so
  they bisected a composed Op to find out. The emitted events already carried
  `$node->id`; `RunResult->error` and anything catching on the durable path did
  not.

  It decorates at the RUNNER, not at the throw site, so **every** node-level
  failure gains attribution — including from executors that know nothing about
  this class. That was the reporter's own preference and it is the right one:
  threading an id through each executor that can fail would have covered the
  failure that prompted it and missed the next one.

  **What a consumer must DO:** nothing, unless you match on the exact text of
  `RunResult->error`. If you do, it now has `node …: ` in front of the original
  message; match on the original with `str_contains()`, or read `nodeId` off the
  exception, which is why it is there.

  **Not a retry, deliberately.** A truncated structured response decodes to
  nothing and is indistinguishable from a model that legitimately found no
  results, so retrying or coercing would silently process zero records. The
  0.15.0 reasoning stands untouched; the only defect was not saying where.

### Fixed

- **`abort()` and `pauseForHuman()` are never decorated.** They are control flow,
  not failure: `abort()` carries its reason verbatim, and `pauseForHuman()` aborts
  with a `Pause::encode()` payload that the durable layer decodes back out of the
  message. Prefixing that payload does not merely read oddly — it stops it being
  decodable, so a run that should be parked waiting on a person is recorded as an
  unrecognised error and is dead.

  Caught while building the change above, which initially wrapped every
  `Throwable`: 72 tests went red and the ones that mattered were not the string
  comparisons but "it asserts a pause". Both cases are now pinned by tests that
  assert the pause still DECODES, rather than asserting on its text.

## 0.19.0 — 2026-08-19

### Added

- **`RunIdentity` on the execution context, so a node that WRITES can send an
  idempotency key.** `ExecutionContext::$run` is new, and
  `$ctx->run->stepKey($ctx->node->id)` is the key.

  Until now the context was `{node, inputs, emit, depth}`, and nothing in it
  could produce a key that is the same on a retry and different on a different
  execution. So every writing connector shipped `unsafe-to-replay` with no key
  at all — meaning **a timed-out payment could never be retried**, because
  retrying it would charge the card a second time.

  What identifies a step is deliberately **not** `(run, node)`: a node
  legitimately runs many times in one run — once per subflow invocation, once
  per loop iteration an executor drives. So the key is the run key plus the
  *path of invocations* that led here, plus an optional occurrence:
  `run_9f2c:billing/pay#3`. `attempt` is carried on the identity and is
  **deliberately absent from the key**; folding it in restores the exact bug the
  key exists to prevent.

  `isReplaySafe($windowSeconds, $now)` answers the other half. Providers forget
  keys (Stripe after 24h), and past that window resending the key and sending a
  fresh one BOTH write twice — so a caller must refuse, loudly, rather than
  choose. Attempt 1 is always safe, which is what lets a run park on a human
  gate for a week and then write.

  Under `per_node`, `attempt` and the first-attempt clock come off the node's
  own **claim row**, so they are exact. The clock is `created_at`, not
  `claimed_at`: `claimed_at` is refreshed on every reclaim, so a retry 25 hours
  late would report itself as seconds old and reuse a key the provider had
  already forgotten. Under `single` the identity is run-scoped and therefore
  conservative — it can only make a window check refuse where `per_node` would
  allow, which is the safe direction.

  Pinned across PHP, TypeScript and Python by `shared/flow-run-identity` in
  `particle-academy/fancy-conformance` (25 rows).

  *Consumer action: none required.* `$ctx->run` is nullable and `null` when a
  host passes no `run` option; existing executors are unaffected. Durable runs
  get it for free — both drivers now supply one. For a synchronous
  `FlowRunner::run()`, pass `new RunOptions(run: $yourStableRunKey)`. It is
  **deliberately not defaulted**: a key minted per call changes on every
  whole-run retry, which is the failure it exists to prevent.

- **`Laravel\Events\HumanInputRequested` — the moment to go and ask.**
  Dispatched by both queue drivers when a run parks on a gate, carrying
  `runId`, `nodeId`, `awaiting` and the kind's `detail` (the form schema, the
  question).

  `WorkflowSettled` already reported that an attempt ended awaiting a human, but
  not which node or what it was asking — so a host wanting to send the email had
  to re-query the run and re-derive the form. That is the kind of obvious next
  step that goes unbuilt, leaving a run parked forever because nobody was ever
  told.

  This is also the step-wise contract stated out loud: the job that reaches a
  gate does its work by FIRING THE REQUEST and then finishing. No worker,
  connection or process is held while a person — who may not be logged in, or
  anywhere near the interface — takes days to answer. The inbound answer is what
  enqueues the continuation.

  *Consumer action: none.* A new event nobody is listening for changes nothing;
  `Event::listen(HumanInputRequested::class, ...)` to use it.

- **`RunOptions::$run`** and **`RunSetup::identityFor()`**, the seams the above
  travel through. `subflow` and `subgraph` push the invoking node onto the
  identity path, so a node inside a child graph cannot share a key with a
  same-named node in the parent.

### Changed

- **`human_approval` now pauses with a detail** — `{title, description}` — where
  it previously passed none. The TypeScript and Python twins have always carried
  one, so this closes a three-runtime divergence, and it is what makes
  `HumanInputRequested` useful for an approval rather than only for a form.

  *Consumer action: none, unless you assert on `awaiting_detail` for an approval
  node — it changes from `null` to an array.* Nothing routes on it.

## 0.18.0 — 2026-08-18

### Security

- **`GraphPolicy::untrusted()` permitted every kind unless you remembered to
  chain `allowKinds()`.** The allowlist defaulted to *absent* rather than empty,
  and absent meant no kind restriction at all — so a policy named `untrusted`,
  applied to a graph from an untrusted author, silently enforced only the size
  and byte caps while reading as though it were locked down.

  The method's own docblock argued the opposite ("an allowlist fails the other
  way, which is the correct way", "empty by design"), and a test asserted that a
  bare `untrusted()` policy raised no issue on an `api_request` node — so the
  behaviour was pinned in place by the suite that should have caught it.

  **BREAKING, and deliberately so: the allowlist is now a required argument.**
  The mistake can no longer be expressed.

  ```php
  // before — compiled, ran, restricted nothing
  GraphPolicy::untrusted()

  // after
  GraphPolicy::untrusted(['manual_trigger', 'transform'])
  ```

  **What to do:** anywhere you call `GraphPolicy::untrusted()`, pass the kinds
  that graph is allowed to use. If you already chained `->allowKinds([...])` you
  were never exposed — move that list into the call and delete the chain. If you
  did **not** chain it, treat any graph you accepted under that policy as having
  been kind-unrestricted.

  Found while building the Python runtime twin, whose equivalent fails closed.

## 0.17.0 — 2026-08-14

### Added

- **`Analysis\SubflowCycle` — catch a subflow loop at AUTHORING time, not on the
  Nth pass of a run** (#5).

  ```php
  $loop = SubflowCycle::find($graph, $resolver, 'Daily Planner');
  // ['Daily Planner', 'Digest', 'Daily Planner']  — or [] when safe
  ```

  `SubflowExecutor`'s depth cap stays and is still the right backstop: it is the
  only thing that catches a loop created from the other end, when someone edits
  B after A was saved. But it fires mid-run, by which point every node ABOVE the
  subflow has already executed on each pass — writes, webhooks, notifications,
  LLM calls — and the author gets an opaque failure from deep inside a run while
  the graph that caused it stays saved and runnable.

  `Workflow::import()` cannot see this: it validates ONE schema in isolation, and
  A → B → A is made of two individually valid graphs. Detection needs the
  resolver, which only the host has — which is exactly why this belongs in the
  package. A host CAN write the ~90 lines (one did), but that code hard-codes the
  `subflow` config key and the ref/version rules, both of which are this
  package's contract, and it has no view of composition kinds added later.

  The chain is returned rather than a boolean, because "this loops" is much less
  useful than naming the step that closes it.

  Three behaviours worth knowing, each of which would otherwise refuse a good
  graph or miss a bad one:
  - a **diamond** is not a cycle — membership is tracked per path, so two
    branches calling the same child is fine;
  - **version pins are distinct** — `A@1 → A@2` is not a loop on its own;
  - it **descends into an inline `subgraph`**, which cannot cycle by itself (it
    embeds a graph rather than naming one) but can contain a `subflow` that does.

  An unresolvable ref, or a resolution failure, is treated as safe: a missing
  workflow is a different problem, and refusing the save for it would block
  authoring a parent before its child exists.

  **What you must do:** nothing — this is additive, and nothing calls it for you.
  Call it before persisting a graph if you want loops refused at the door.
  Parity: the TS twin ships as `findSubflowCycle` in
  `@particle-academy/fancy-flow/engine`.

## 0.16.0 — 2026-08-12

### Added

- **`Security\GraphPolicy` — the trust layer for graphs you did not write.**
  `Workflow::import()` answers "is this graph COHERENT?"; this answers "is it
  safe to ACCEPT?" A graph arriving over HTTP from a stranger is a payload
  first and a workflow second, and it gets persisted to a queue table and later
  rehydrated by a worker that trusts it.

  ```php
  use FancyFlow\Security\GraphPolicy;

  $policy = GraphPolicy::untrusted()
      ->allowKinds(['manual_trigger', 'user_input', 'branch', 'transform', 'llm_call', 'output'])
      ->withLimits(maxNodes: 25);

  $policy->assert($schema);   // throws UnsafeGraph, carrying EVERY issue
  $issues = $policy->inspect($schema);   // or collect them for a UI
  ```

  What it checks:

  - **Kind policy.** An allowlist decides which executors a stranger may cause
    to run. `untrusted()` ships with NO allowlist on purpose — this package
    cannot guess which of its kinds are safe in your app, so naming them is
    yours.
  - **Size caps.** Nodes, edges, nesting depth, string length, total bytes. A
    deeply nested config is a stack overflow in whatever parses it next.
  - **Byte hygiene.** Invalid UTF-8, NUL, and C0/C1 control characters are
    refused in every string — including keys. These do not occur in real
    workflows and are what gets used to smuggle content past a log, a terminal,
    or a downstream parser that disagrees with PHP about where a string ends.
    Tab, newline and carriage return are allowed, because prompts contain them.
  - **Structure.** Duplicate node ids and edges pointing at nodes that do not
    exist.
  - **Host rules.** `addRule()` takes a closure, so a host can assert what this
    package cannot know without forking the class.

  **The kind policy is ALIAS-AWARE, and that is the point.** A kind answers to
  several ids (`api_request`, `@particle-academy/api_request`,
  `@fancy/api_request`). A denylist keyed on the literal string you happened to
  write is not a denylist — it is a suggestion the attacker declines by
  spelling the kind differently. Every id is resolved before any comparison.

  Allowlists are recommended over denylists for exactly the reason the two
  behave differently under a respelling: an allowlist fails closed, a denylist
  does not.

  The policy is immutable — every wither returns a clone — so a base policy
  shared between call sites cannot be widened by one of them.

## 0.15.1 — 2026-08-12

### Fixed

- **A human gate addressed by its CANONICAL kind id did not pause — the run
  went straight past the person** (#4). A `user_input` or `human_approval` node
  saved as `@particle-academy/user_input` ran as a pass-through with empty
  output, the run reached `completed`, and downstream nodes received empty
  values. The bare `user_input` paused correctly, which is what made this so
  easy to miss.

  **This is the id an editor persists**, so every human step authored in the
  editor was affected — precisely defeating the "human gates fail closed"
  guarantee for the ids that actually get written to documents.

  The cause was `ExecutorRegistry::bind()` keying literally. The builtins are
  bound under all three ids, resolution tries the node's literal id FIRST, and
  the durable overrides were bound under the bare name only — so the canonical
  id matched the plain pass-through executor and the override was never
  reached. Nothing errored, which is what let it ship.

  `bind()` is now alias-aware for kinds the package knows: binding `user_input`
  binds `@particle-academy/user_input` and `@fancy/user_input` with it. That
  fixes the same trap for **any host** overriding a builtin by bare name, which
  is the more important half — the durable executors were just the first
  casualty.

  **What to do:** upgrade. No API changed and no configuration is involved. If
  you deliberately bound only one spelling of a builtin expecting the others to
  fall through elsewhere, that no longer happens; binding an UNKNOWN kind is
  still literal, so third-party kinds are unaffected.

### Changed

- `Registry\Builtin::kindIdIndex()` is public. An override has to agree with
  the bindings it overrides, and the kind registry is not necessarily populated
  at bind time, so it cannot be the only source of a kind's ids.

## 0.15.0 — 2026-08-12

### Added

- **Schema-typed output for `llm_call`** (fancy-flow#6). Set `response_schema`
  (JSON Schema) on the node and it emits the *parsed* value as `data` alongside
  `text`, so a downstream node can write `{{ $json.data[0].title }}` instead of
  parsing a string.

  The schema is carried to the adapter in `$options['response_schema']`, so a
  client that supports provider-native structured output (Anthropic tool result,
  OpenAI `response_format: json_schema`) can constrain the model rather than
  relying on prompt wording. An adapter that ignores it still works: the node
  extracts and validates from `text`.

  **`data` supplied by an adapter is validated, not trusted.** "The provider
  promised" is not the same as "the provider did", and the point of asking for a
  schema is that the next node can rely on the shape.

  **A response that cannot be parsed, or does not match, FAILS the node.** That
  is the behaviour change worth knowing about, and it is deliberate: a truncated
  array decodes to nothing and is indistinguishable from a model that found no
  results, so the old prompt-and-parse approach turned a truncation into a
  workflow that quietly processed zero records.

  **What to do:** nothing, unless you want it. `llm_call` without
  `response_schema` behaves exactly as before — same options out, same result
  back, no `data` key.

- **`StructuredOutput`** — the extractor and validator behind it. Recovers JSON
  from a ```` ```json ```` fence and from a prose preamble or trailing note, both
  of which models emit despite instructions; raises on truncation rather than
  guessing.

  The validator enforces a documented SUBSET of JSON Schema — `type`,
  `required`, `properties`, `items`, `enum` — and ignores the rest. Said out
  loud in the class docblock and pinned by a test, because a validator that
  silently skips the keyword you relied on is worse than one that names what it
  checks. Dependency-free: core takes no runtime dependencies.

### Changed

- `Nodes\Support\LlmClient::complete()` documents `response_schema` in
  `$options` and `data` in its return shape. **The interface signature is
  unchanged** — both are optional, so every existing implementation still
  satisfies it and needs no edit.

## 0.14.3 — 2026-08-11

### Changed

- **`satisfiesRange` now asserts the SHARED table instead of a copy of it.** The
  test carried its own hand-transcribed 17 rows — a duplicate of
  `shared/satisfies-range`, which is the exact thing the conformance package
  exists to remove. Two copies agree right up until someone adds a row to one of
  them, and nothing anywhere reports that.

  Same 17 rows, same results; they now come from
  `Conformance::runTable()` and cannot drift from the copy
  `@particle-academy/fancy-flow` and `fancy-ui-cli` assert.

### Fixed

- **The roadmap in `AGENTS.md` said `v0.11.0` while the package was on
  `v0.14.1`** — three minors of shipped work described as planned, including the
  PHP 8.4 floor and the 0.13 human-gate behaviour changes. An agent reading it
  would have believed `autoAnswerFromInput` and `LiveContract` did not exist.

  Test-only and docs-only; no runtime change, and consumers do nothing.

---

## 0.14.2 — 2026-08-11

### Added

- **The `shared/expr` conformance table runs here now**, against
  `Nodes\Support\Expr`. `@particle-academy/fancy-flow` runs the identical rows
  from the identical file, so a `{{ }}` divergence between the two runtimes is a
  red build in whichever one drifted rather than a support ticket months later.

  20 cases: dot-path resolution, the `$json` / `$input` aliases, the
  whole-string-keeps-its-type rule, interpolation stringifying, and truthiness.
  Truthiness carries the weight — `"0"`, `"false"` and `[]` are all truthy in
  JavaScript and falsy here, and a branch node reading a form value or a JSON
  body hits every one of them.

  Verified the table can actually fail rather than trusting a green tick:
  forwarding `Expr::truthy` to native PHP truthiness reddens cases 0013 and 0019
  and nothing else.

  Worth recording why this arrived only now. `Expr` shipped here with no
  TypeScript twin at all until `fancy-flow` 0.43.0, and a conformance table
  cannot catch that: it compares two implementations and reports that they
  DISAGREE, while an absent one has nothing to run the rows against. It guards
  drift, not absence.

  **Test-only.** `particle-academy/fancy-conformance` is a `require-dev`;
  nothing about the runtime changed and consumers do nothing.

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
