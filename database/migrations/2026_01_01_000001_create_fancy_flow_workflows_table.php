<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function table(): string
    {
        return (string) config('fancy-flow.persistence.table_prefix', 'fancy_flow_').'workflows';
    }

    public function up(): void
    {
        Schema::create($this->table(), function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->nullableMorphs('workflowable'); // attach a flow to any model (HasWorkflows)
            $table->json('schema');                 // the stored WorkflowSchema
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->table());
    }
};
