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
        Schema::table('dealers', function (Blueprint $table): void {
            $table->foreignId('referrer_id')->nullable()->constrained('dealers')->restrictOnDelete();
            $table->index('referrer_id');
            $table->foreignId('dealership_request_id')->nullable()->unique()->constrained('dealership_requests')->restrictOnDelete();
        });
        Schema::table('dealership_requests', function (Blueprint $table): void {
            $table->foreignId('referrer_id')->nullable()->constrained('dealers')->restrictOnDelete();
            $table->string('referral_code', 30)->nullable();
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->json('referral_snapshot')->nullable();
        });
        Schema::table('business_config', function (Blueprint $table): void {
            $table->foreignId('active_rule_version_id')->nullable()->constrained('rule_versions')->restrictOnDelete();
            $table->unsignedInteger('rule_revision')->default(1);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('business_config', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('active_rule_version_id');
            $table->dropColumn('rule_revision');
        });
        Schema::table('purchases', function (Blueprint $table): void {
            $table->dropColumn('referral_snapshot');
        });
        Schema::table('dealership_requests', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('referrer_id');
            $table->dropColumn('referral_code');
        });
        Schema::table('dealers', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dealership_request_id');
            $table->dropIndex(['referrer_id']);
            $table->dropConstrainedForeignId('referrer_id');
        });
    }
};
