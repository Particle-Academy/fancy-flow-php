<?php

declare(strict_types=1);

use FancyFlow\Capabilities\Capabilities;
use FancyFlow\Capabilities\WorkflowResolutionFailure;
use FancyFlow\Capabilities\WorkflowResolver;
use FancyFlow\Laravel\Facades\FancyFlow;
use FancyFlow\Laravel\Models\WorkflowRun;
use FancyFlow\Runtime\ExecutionContext;
use FancyFlow\Schema\FlowGraph;
use FancyFlow\Workflow;

uses(\FancyFlow\Tests\Durable\PerNodeTestCase::class);

afterEach(fn () => Capabilities::reset());

/**
 * Does work already COMMITTED inside a child survive the parent's pause?
 *
 * This is fancy-flow-php#19's requirement (1) and (3) one level up, and the
 * question the "a subflow is itself a pausing node" reframe does not by itself
 * answer. Addressing the answer to the subflow node solves WHERE the answer
 * goes. It says nothing about whether the child's earlier nodes re-execute when
 * the parent resumes -- and a child whose first node WRITES cannot be re-run.
 */
it('counts how many times a child node before the gate executes across a pause', function (): void {
    $sideEffects = 0;

    $child = Workflow::import(
        [
            '$schema' => Workflow::SCHEMA_URL, 'version' => 1,
            'graph' => [
                'nodes' => [
                    ['id' => 'c_in', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                    ['id' => 'writes', 'kind' => 'sfWriter', 'position' => ['x' => 0, 'y' => 1], 'config' => []],
                    ['id' => 'gate', 'kind' => 'human_approval', 'position' => ['x' => 0, 'y' => 2], 'config' => []],
                    ['id' => 'c_out', 'kind' => 'output', 'position' => ['x' => 0, 'y' => 3], 'config' => []],
                ],
                'edges' => [
                    ['id' => 'ce1', 'source' => 'c_in', 'target' => 'writes'],
                    ['id' => 'ce2', 'source' => 'writes', 'target' => 'gate'],
                    ['id' => 'ce3', 'source' => 'gate', 'target' => 'c_out', 'sourceHandle' => 'approved'],
                ],
            ],
        ],
        lenient: true,
        registry: app(\FancyFlow\NodeKindRegistry::class),
    )->graph;

    Capabilities::setWorkflowResolver(new class($child) implements WorkflowResolver
    {
        public function __construct(private FlowGraph $child) {}

        public function resolve(string $ref, ?int $version = null): FlowGraph|WorkflowResolutionFailure|null
        {
            return $ref === 'child' ? $this->child : null;
        }
    });

    app(\FancyFlow\ExecutorRegistry::class)->bind('sfWriter', function (ExecutionContext $ctx) use (&$sideEffects) {
        $sideEffects++;

        return ['wrote' => $sideEffects];
    });

    $run = FancyFlow::dispatch(
        [
            '$schema' => Workflow::SCHEMA_URL, 'version' => 1,
            'graph' => [
                'nodes' => [
                    ['id' => 'trigger', 'kind' => 'manual_trigger', 'position' => ['x' => 0, 'y' => 0], 'config' => []],
                    ['id' => 'call', 'kind' => 'subflow', 'position' => ['x' => 0, 'y' => 1], 'config' => ['workflow' => 'child']],
                    ['id' => 'end', 'kind' => 'output', 'position' => ['x' => 0, 'y' => 2], 'config' => []],
                ],
                'edges' => [
                    ['id' => 'e1', 'source' => 'trigger', 'target' => 'call'],
                    ['id' => 'e2', 'source' => 'call', 'target' => 'end'],
                ],
            ],
        ],
        ['trigger' => ['deal' => 1]],
    );
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::AWAITING_APPROVAL);
    expect($sideEffects)->toBe(1, 'the child ran up to the gate once');

    $run->approve();
    $run->refresh();

    expect($run->status)->toBe(WorkflowRun::COMPLETED);

    // THE MEASUREMENT. 1 means the child's committed work survived the pause.
    // 2 means it re-ran -- and a node that WRITES would have written twice.
    expect($sideEffects)->toBe(1, 'a committed child node must not re-execute on resume');
})->skip(
    'KNOWN FAILURE, measured at 2 (fancy-flow-php#22/#19). A child node that already '
    .'ran before a gate RE-EXECUTES when the parent resumes, so a child that writes '
    .'writes twice. SubflowExecutor runs the child fresh on every parent attempt and '
    .'passes no resumeOutputs down, and per_node gives the whole child ONE claim row -- '
    .'the subflow node -- so nothing inside it is checkpointed independently. Committed '
    .'skipped rather than deleted so the number is on the record and this flips green '
    .'the day work at depth is checkpointed at depth. That is the same requirement '
    .'fancy-flow-php#19 states for items 1..k-1 of an iterated body, which is why it is '
    .'being settled in that design rather than patched here.'
);
