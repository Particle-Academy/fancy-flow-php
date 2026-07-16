<?php

declare(strict_types=1);

namespace FancyFlow\Laravel\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A stored workflow — its WorkflowSchema JSON, versioned, optionally attached to
 * a host model via {@see \FancyFlow\Laravel\Concerns\HasWorkflows}.
 *
 * @property array<string,mixed> $schema
 */
class Workflow extends Model
{
    protected $guarded = [];

    protected $casts = [
        'schema' => 'array',
        'version' => 'integer',
    ];

    public function __construct(array $attributes = [])
    {
        $this->table = (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflows';
        parent::__construct($attributes);
    }

    public function workflowable(): MorphTo
    {
        return $this->morphTo();
    }

    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class);
    }
}
