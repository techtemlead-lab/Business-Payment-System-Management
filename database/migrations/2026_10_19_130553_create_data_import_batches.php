<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('data_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type', 32)->index();
            $table->string('status', 32)->index();
            $table->string('filename');
            $table->string('file_path');
            $table->string('file_hash', 64)->index();
            $table->string('source_reference', 150);
            $table->json('rows');
            $table->json('summary');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('applied_by')->nullable()->constrained('users');
            $table->timestamp('applied_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->boolean('is_opening_balance')->default(false);
        });
        Schema::create('opening_balance_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('import_id')->constrained('data_import_batches');
            $table->foreignId('user_id')->unique()->constrained('users');
            $table->string('reference', 100)->unique();
            $table->date('as_of_date');
            $table->decimal('available', 14, 2);
            $table->decimal('held', 14, 2);
            $table->decimal('held_released', 14, 2)->default(0);
            $table->foreignUuid('order_id')->nullable()->constrained('orders');
            $table->foreignId('released_by')->nullable()->constrained('users');
            $table->timestamp('released_at')->nullable();
            $table->text('release_reason')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('opening_balance_entries');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('is_opening_balance'));
        Schema::dropIfExists('data_import_batches');
    }
};
