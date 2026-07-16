<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Jobs;

use FancyFlow\Exceptions\FlowException;

/** Thrown by {@see RunWorkflowJob} on a genuine run failure so the queue retries it. */
final class WorkflowRunFailed extends FlowException {}
