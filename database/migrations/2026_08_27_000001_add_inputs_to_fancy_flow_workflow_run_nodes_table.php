<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_run_nodes';
    }

    public function up(): void
    {
        if (! Schema::hasTable($this->table()) || Schema::hasColumn($this->table(), 'inputs')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            // The exact port map delivered in ExecutionContext. Nullable is a
            // truthful compatibility state for old, skipped, and adopted rows:
            // none of those inputs can be reconstructed after the fact.
            $table->json('inputs')->nullable()->after('owner');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable($this->table()) || ! Schema::hasColumn($this->table(), 'inputs')) {
            return;
        }

        Schema::table($this->table(), function (Blueprint $table) {
            $table->dropColumn('inputs');
        });
    }
};
