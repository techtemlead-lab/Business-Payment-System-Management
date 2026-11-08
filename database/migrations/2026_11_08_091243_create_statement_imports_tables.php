<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('statement_imports', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('channel', 20);
            $table->string('status', 30)->default('awaiting_mapping');
            $table->string('filename');
            $table->string('file_path');
            $table->string('file_hash', 64)->unique();
            $table->string('source_reference', 150);
            $table->json('raw_rows');
            $table->json('mapping')->nullable();
            $table->json('summary')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('created_at');
        });
        Schema::create('statement_import_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('statement_import_id')->constrained('statement_imports')->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('txn_date', 40)->nullable();
            $table->string('reference', 120)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('state', 20)->default('no_match');
            $table->foreignId('suggested_claim_id')->nullable()->constrained('payment_claims');
            $table->json('flags')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['statement_import_id', 'row_number']);
            $table->index('reference');
            $table->index('suggested_claim_id');
        });
        // One payment claim can be confirmed against only one statement line.
        DB::statement("CREATE UNIQUE INDEX statement_lines_one_confirmed_per_claim ON statement_import_lines (suggested_claim_id) WHERE state = 'confirmed'");
    }

    public function down(): void
    {
        Schema::dropIfExists('statement_import_lines');
        Schema::dropIfExists('statement_imports');
    }
};
