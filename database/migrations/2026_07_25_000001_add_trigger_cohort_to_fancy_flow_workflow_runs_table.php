<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups the runs that one trigger event fired, so they can't race each other.
 *
 * Dispatching N workflows off a single event used to produce N independent jobs
 * in whatever order the queue felt like. If one of them mutated or DELETED the
 * record that fired the trigger, the others ran afterwards against state that no
 * longer existed — silently, because a run over missing input still reports
 * success. Nothing ordered them and nothing checked.
 *
 * A cohort gives that fan-out an identity (`cohort_key`), a deterministic order
 * (`cohort_seq`), a declared collision `cohort_policy`, and an optional `guard`
 * re-checked immediately before each run starts. A run whose guard fails is
 * SKIPPED with `skipped_reason` recorded, rather than executing on nothing.
 *
 * Every column is nullable and additive. A run dispatched the ordinary way has
 * no cohort and behaves exactly as before.
 */
return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_runs';
    }

    public function up(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->string('cohort_key')->nullable()->index()->after('run_key');
            $table->unsignedInteger('cohort_seq')->nullable()->after('cohort_key');
            $table->string('cohort_policy')->nullable()->after('cohort_seq');
            $table->json('guard')->nullable()->after('cohort_policy');
            $table->text('skipped_reason')->nullable()->after('guard');
        });
    }

    public function down(): void
    {
        // The index goes first: SQLite rebuilds the table on a column drop and
        // refuses if an index still references the departing column.
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropIndex(['cohort_key']);
        });

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['cohort_key', 'cohort_seq', 'cohort_policy', 'guard', 'skipped_reason']);
        });
    }
};
