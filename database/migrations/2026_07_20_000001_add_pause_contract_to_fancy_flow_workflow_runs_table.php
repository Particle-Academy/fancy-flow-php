<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records WHAT a paused run is waiting for, not just where.
 *
 * Before the pause contract, the only two waits that existed were approval and
 * input, and each had its own status — so `awaiting_node` was enough. A
 * third-party node can now declare its own wait (a signature, a payment, a
 * review queue), and a run parked on one has to remember which, or a host has
 * no way to render the right prompt on resume.
 *
 * Both columns are nullable and additive: runs paused before this migration
 * keep working, they simply have no recorded kind — which the model reports as
 * the legacy approval/input inferred from `status`.
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
            $table->string('awaiting_kind')->nullable()->after('awaiting_node');
            $table->json('awaiting_detail')->nullable()->after('awaiting_kind');
        });
    }

    public function down(): void
    {
        Schema::table($this->table(), function (Blueprint $table): void {
            $table->dropColumn(['awaiting_kind', 'awaiting_detail']);
        });
    }
};
