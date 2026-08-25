<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workflow-level props a durable run was started with.
 *
 * Distinct from `initial_inputs`, which is keyed by NODE ID and seeds one entry
 * point. Props are keyed by NAME, checked against the graph's own `inputs`
 * declaration, and made available to EVERY node — so a value is not threaded
 * edge by edge through nodes that have no interest in it.
 *
 * Nullable, and null reads as "none passed", which is what every run recorded
 * before this column existed did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->json('props')->nullable()->after('initial_inputs');
        });
    }

    public function down(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('props');
        });
    }
};
