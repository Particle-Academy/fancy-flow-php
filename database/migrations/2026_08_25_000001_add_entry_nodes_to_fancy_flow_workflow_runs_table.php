<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which entry points a durable run started from.
 *
 * NULLABLE, and null means "unset" rather than "none" — the two are different
 * and the distinction is load-bearing. Every run recorded before this column
 * existed is null, and must keep behaving exactly as it did: every entry point
 * live. A default of `[]` would have said "no entry point is live" and quietly
 * stopped every historical run from ever resuming.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->json('entry_nodes')->nullable()->after('initial_inputs');
        });
    }

    public function down(): void
    {
        Schema::table('fancy_flow_workflow_runs', function (Blueprint $table): void {
            $table->dropColumn('entry_nodes');
        });
    }
};
