<?php

declare(strict_types=1);

use FancyFlow\Authoring\FlowAuthoring;
use FancyFlow\NodeKindRegistry;
use FancyFlow\Registry\NodeKind;
use FancyFlow\Schema\PortDescriptor;

/**
 * The authoring catalogue must include the HOST's kinds, not only ours.
 *
 * `FlowAuthoring::default()` built a registry of builtins alone, so an agent
 * authoring through this server could not see — or connect to — any kind the
 * host application had registered. For a host whose workflows are mostly built
 * from its own kinds, that is the difference between an MCP that can author its
 * real graphs and one that can only author ours.
 *
 * Why it matters more than "some kinds are missing": `connect_nodes` validates
 * source and target ports against the kind. An edge naming a source port
 * nothing publishes does NOT fail and does NOT warn —
 * `FlowRunner::collectInputs` binds a payload only when
 * `"<sourceId>:<handle>"` exists, so the edge silently delivers nothing. The
 * downstream template is then completely correct and renders empty, because the
 * payload never arrived to have a field in it.
 *
 * A consumer misdiagnosed two filed issues off the back of exactly that, and an
 * agent "fixed" one by correcting a field name that was never wrong. Authoring
 * through an API that knows the ports removes the string there is to get wrong
 * — but only for kinds the API can see.
 */
function hostRegistry(): NodeKindRegistry
{
    $r = new NodeKindRegistry();

    $r->register(new NodeKind(
        name: 'deal_list',
        category: 'data',
        label: 'Deal List',
        outputs: [new PortDescriptor('matched'), new PortDescriptor('empty')],
        outputShape: [
            ['path' => 'deals', 'type' => 'array'],
            ['path' => 'count', 'type' => 'number'],
        ],
    ));

    return $r;
}

it('lists a host kind alongside the builtins', function () {
    $authoring = FlowAuthoring::withHostKinds(hostRegistry());

    expect($authoring->kinds()->get('deal_list'))->not->toBeNull();
    // And has not lost ours.
    expect($authoring->kinds()->get('llm_call'))->not->toBeNull();
});

it('describes a host kind with its PORTS, which is what connect_nodes validates', function () {
    $authoring = FlowAuthoring::withHostKinds(hostRegistry());
    $described = $authoring->describeKind($authoring->kinds()->get('deal_list'));

    // `ports.outputs` is a list of port ID STRINGS, not descriptor arrays --
    // `PortResolver::declared()` maps each PortDescriptor to its `->id`. My
    // first draft ran array_column(..., 'id') over it and got [].
    expect($described['ports']['outputs'])->toBe(['matched', 'empty']);

    // Without this, an agent connecting from `deal_list` has no way to learn
    // that `matched` exists and `out` does not -- and naming a port that does
    // not exist fails silently at run time rather than at authoring time.
});

it('describes a host kind with its declared FIELDS', function () {
    $authoring = FlowAuthoring::withHostKinds(hostRegistry());
    $described = $authoring->describeKind($authoring->kinds()->get('deal_list'));

    expect(array_column($described['emits']['fields'], 'path'))->toBe(['deals', 'count']);
});

it('a host kind OVERRIDES a builtin of the same name', function () {
    // A host overriding a builtin means it. An authoring surface that showed
    // the builtin instead would describe ports the run does not have — which is
    // the same silent-nothing failure, produced by us.
    $host = new NodeKindRegistry();
    $host->register(new NodeKind(
        name: 'notify',
        category: 'human',
        label: 'Notify (host)',
        outputs: [new PortDescriptor('queued')],
    ));

    $authoring = FlowAuthoring::withHostKinds($host);
    $described = $authoring->describeKind($authoring->kinds()->get('notify'));

    expect($described['label'])->toBe('Notify (host)');
    expect($described['ports']['outputs'])->toBe(['queued']);
});

it('still works with no host registry at all', function () {
    // The isolated-catalogue behaviour that existed before, unchanged: a server
    // with no host kinds gets the builtins and nothing else.
    $authoring = FlowAuthoring::withHostKinds(null);

    expect($authoring->kinds()->get('llm_call'))->not->toBeNull();
    expect($authoring->kinds()->get('deal_list'))->toBeNull();
});

it('owns its registry — registering into one catalogue does not touch another', function () {
    // The reason the isolated instance existed. Host kinds are COPIED in, so
    // two concurrent servers cannot clobber each other's catalogue.
    $a = FlowAuthoring::withHostKinds(hostRegistry());
    $b = FlowAuthoring::withHostKinds(null);

    $a->kinds()->register(new NodeKind(name: 'only_in_a', category: 'custom', label: 'A'));

    expect($b->kinds()->get('only_in_a'))->toBeNull();
});
