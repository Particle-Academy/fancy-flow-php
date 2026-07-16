<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflow_runs';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('run_key')->unique();
            $table->unsignedBigInteger('workflow_id')->nullable()->index();
            $table->string('status')->default('pending')->index();
            $table->json('schema');                       // self-contained: replayable independent of the Workflow row
            $table->json('initial_inputs')->nullable();
            $table->json('node_outputs')->nullable();     // raw per-node results — the resume checkpoint
            $table->json('outputs')->nullable();          // final outputs
            $table->json('events')->nullable();           // event log
            $table->json('approvals')->nullable();        // nodeId => bool, for human_approval pauses
            $table->string('awaiting_node')->nullable();  // the node the run is paused at
            $table->text('error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
