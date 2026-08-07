<?php

declare(strict_types=1);

namespace FancyFlow\Exceptions;

/**
 * A human answer was recorded against a run that never asked for it.
 *
 * Thrown by {@see \FancyFlow\Laravel\Models\WorkflowRun::submitInput()},
 * `approve()` and `deny()` when the run is not parked on that node.
 *
 * This is loud on purpose. The alternative — accepting the answer — is what let
 * a human gate be skipped entirely: a submission recorded before the node ever
 * ran was replayed into it as `values`, the executor saw a non-null input, and
 * the run continued past the step a person was supposed to approve. Silently
 * ignoring the answer instead would trade that for the opposite failure, where a
 * person submits a form and nothing happens.
 */
final class NotAwaitingHuman extends FlowException
{
    public static function for(string $runKey, ?string $nodeId, string $status, ?string $awaiting): self
    {
        $target = $nodeId === null || $nodeId === '' ? '(no node given)' : "'{$nodeId}'";

        $parked = $awaiting === null || $awaiting === ''
            ? 'it is not parked on any node'
            : "it is parked on '{$awaiting}'";

        return new self(
            "Run {$runKey} is not awaiting a human answer for {$target}: status is '{$status}' and {$parked}. ".
            'Record an answer only while the run is parked on that node — a submission stored for a node that '.
            'has not paused is replayed into it as input, which skips the step instead of resuming it.'
        );
    }
}
