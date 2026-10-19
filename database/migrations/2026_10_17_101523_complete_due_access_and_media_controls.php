<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', fn (Blueprint $table) => $table->decimal('due_credit', 14, 2)->default(0));
        Schema::table('order_lines', fn (Blueprint $table) => $table->string('category_snapshot', 100)->nullable());
        DB::statement('UPDATE order_lines l SET category_snapshot=p.category FROM products p WHERE p.id=l.product_id');
        Schema::table('withdrawals', fn (Blueprint $table) => $table->decimal('minimum_snapshot', 14, 2)->nullable());
        Schema::table('products', function (Blueprint $table): void {
            $table->string('video_vimeo_id', 24)->nullable();
            $table->string('video_vimeo_hash', 64)->nullable();
            $table->text('video_external_url')->nullable();
        });
        Schema::create('business_settings', function (Blueprint $table): void {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('staff_roles', function (Blueprint $table): void {
            $table->string('key', 60)->primary();
            $table->string('name', 100);
            $table->json('permissions');
            $table->unsignedInteger('version')->default(1);
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
        });
        Schema::create('due_adjustments', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('kind', 24);
            $table->decimal('amount', 14, 2);
            $table->string('status', 20)->default('requested');
            $table->uuid('request_key')->unique();
            $table->string('reference', 100)->unique();
            $table->text('reason');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->text('review_reason')->nullable();
            $table->decimal('before_outstanding', 14, 2)->nullable();
            $table->decimal('after_outstanding', 14, 2)->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
        });
        DB::statement('ALTER TABLE orders ADD CONSTRAINT order_due_credit_bounds CHECK (due_credit >= 0 AND due_credit <= total-paid)');
        DB::statement('ALTER TABLE due_adjustments ADD CONSTRAINT due_adjustment_amount_positive CHECK (amount > 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('due_adjustments');
        Schema::dropIfExists('staff_roles');
        Schema::dropIfExists('business_settings');
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['video_vimeo_id', 'video_vimeo_hash', 'video_external_url']));
        Schema::table('withdrawals', fn (Blueprint $table) => $table->dropColumn('minimum_snapshot'));
        DB::statement('ALTER TABLE orders DROP CONSTRAINT order_due_credit_bounds');
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('due_credit'));
        Schema::table('order_lines', fn (Blueprint $table) => $table->dropColumn('category_snapshot'));
    }
};
