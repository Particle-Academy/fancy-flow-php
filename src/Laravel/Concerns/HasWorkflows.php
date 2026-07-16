<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Concerns;

use FancyFlow\Laravel\Models\Workflow;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Attach stored workflows to any Eloquent model.
 *
 *     class Project extends Model { use HasWorkflows; }
 *     $project->workflows()->create(['name' => 'Onboarding', 'schema' => $schema]);
 *
 * @mixin \Illuminate\Database\Eloquent\Model
 */
trait HasWorkflows
{
    public function workflows(): MorphMany
    {
        return $this->morphMany(Workflow::class, 'workflowable');
    }
}
