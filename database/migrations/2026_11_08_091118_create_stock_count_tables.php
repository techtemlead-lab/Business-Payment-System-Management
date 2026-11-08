<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stock-count sessions per branch. A branch can have only one count open at a time.
     */
    public function up(): void
    {
        Schema::create('stock_count_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('branch_id')->constrained('company_branches')->restrictOnDelete();
            $table->string('status', 20)->default('counting');
            $table->foreignId('started_by')->constrained('users');
            $table->foreignId('submitted_by')->nullable()->constrained('users');
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'status', 'decided_at']);
        });
        DB::statement("CREATE UNIQUE INDEX stock_count_one_open_per_branch ON stock_count_sessions (branch_id) WHERE status IN ('counting','submitted')");

        Schema::create('stock_count_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_count_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->integer('expected_quantity')->default(0);
            $table->integer('counted_quantity')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users');
            $table->timestamp('counted_at')->nullable();
            $table->string('stock_reference')->nullable()->unique();
            $table->timestamps();
            $table->unique(['stock_count_session_id', 'product_id']);
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_count_lines');
        Schema::dropIfExists('stock_count_sessions');
    }
};
