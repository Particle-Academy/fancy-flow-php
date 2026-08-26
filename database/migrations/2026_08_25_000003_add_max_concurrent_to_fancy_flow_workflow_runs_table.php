<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How many of a run's nodes may be in flight at once, under the `per_node`
 * driver.
 *
 * Nullable, and null means "fall back to the config default", which is itself
 * null for "unlimited" — today's behaviour. Storing it per RUN rather than only
 * in config is the point of the feature: worker topology (one worker on the
 * queue) already serialises everything, but it is a deployment-wide setting
 * standing in for a per-run one, and it stops being true the moment anyone
 * scales the worker for throughput.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('max_concurrent')->nullable()->after('props');
        });
    }

    public function down(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('max_concurrent');
        });
    }
};
