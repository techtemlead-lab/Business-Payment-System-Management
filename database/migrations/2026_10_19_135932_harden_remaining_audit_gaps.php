<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_applications', function (Blueprint $table): void {
            $table->dropForeign(['submitted_by']);
        });
        DB::statement('ALTER TABLE dealer_applications ALTER COLUMN submitted_by DROP NOT NULL');
        Schema::table('dealer_applications', function (Blueprint $table): void {
            $table->foreign('submitted_by')->references('id')->on('users')->nullOnDelete();
            $table->string('source', 40)->default('staff')->after('status');
            $table->index('source');
        });

        Schema::create('commission_settlement_runs', function (Blueprint $table): void {
            $table->id();
            $table->string('period_month', 7);
            $table->string('kind', 20);
            $table->uuid('request_key');
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('result');
            $table->timestamps();
            $table->unique(['period_month', 'kind', 'request_key'], 'settlement_runs_period_kind_key_unique');
        });

        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->index(['account', 'user_id'], 'journal_lines_account_user_index');
        });

        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->index(['product_id', 'created_at'], 'stock_movements_product_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('stock_movements', function (Blueprint $table): void {
            $table->dropIndex('stock_movements_product_created_at_index');
        });
        Schema::table('journal_lines', function (Blueprint $table): void {
            $table->dropIndex('journal_lines_account_user_index');
        });
        Schema::dropIfExists('commission_settlement_runs');

        Schema::table('dealer_applications', function (Blueprint $table): void {
            $table->dropIndex(['source']);
            $table->dropColumn('source');
            $table->dropForeign(['submitted_by']);
        });
        DB::statement('UPDATE dealer_applications SET submitted_by = (SELECT id FROM users ORDER BY id ASC LIMIT 1) WHERE submitted_by IS NULL');
        DB::statement('ALTER TABLE dealer_applications ALTER COLUMN submitted_by SET NOT NULL');
        Schema::table('dealer_applications', function (Blueprint $table): void {
            $table->foreign('submitted_by')->references('id')->on('users');
        });
    }
};
