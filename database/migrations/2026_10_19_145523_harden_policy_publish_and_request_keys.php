<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_drafts', function (Blueprint $table): void {
            $table->foreignId('published_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable()->after('published_by');
        });

        Schema::table('commission_refunds', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable()->after('reference');
            $table->unique('request_key', 'commission_refunds_request_key_unique');
        });

        Schema::table('rank_incentive_awards', function (Blueprint $table): void {
            $table->uuid('request_key')->nullable()->after('amount');
            $table->unique('request_key', 'rank_incentive_awards_request_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('rank_incentive_awards', function (Blueprint $table): void {
            $table->dropUnique('rank_incentive_awards_request_key_unique');
            $table->dropColumn('request_key');
        });

        Schema::table('commission_refunds', function (Blueprint $table): void {
            $table->dropUnique('commission_refunds_request_key_unique');
            $table->dropColumn('request_key');
        });

        Schema::table('policy_drafts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('published_by');
            $table->dropColumn('published_at');
        });
    }
};
