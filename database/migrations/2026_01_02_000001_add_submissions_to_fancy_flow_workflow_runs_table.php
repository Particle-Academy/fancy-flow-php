<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `submissions` column beside `approvals` — the typed resume payloads
 * for durable `user_input` pauses (nodeId => submitted form values), where
 * `approvals` only carries bool decisions.
 */
return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_runs';
    }

    public function up(): void
    {
        // Idempotent: the table may not exist yet when persistence is off, and
        // a republished create-migration may already carry the column.
        if (! Schema::hasTable($this->table()) || Schema::hasColumn($this->table(), 'submissions')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->json('submissions')->nullable(); // nodeId => submitted values, for user_input pauses
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table()) || ! Schema::hasColumn($this->table(), 'submissions')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn('submissions');
        });
    }
};
