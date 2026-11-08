<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DrawingsName = "Owner's drawings";

    private const DrawingsNameBn = 'মালিকের উত্তোলন';

    private const OldDrawingsName = 'Owner withdrawal';

    /**
     * Records a fresh start and a one-person publish, and names the owner's drawings head.
     */
    public function up(): void
    {
        Schema::table('financial_verifications', function (Blueprint $table): void {
            $table->boolean('fresh_start')->default(false);
        });
        Schema::table('policy_drafts', function (Blueprint $table): void {
            $table->boolean('self_approved')->default(false);
        });

        DB::table('fund_expense_heads')->where('name_en', self::OldDrawingsName)->update(['name_en' => self::DrawingsName, 'name_bn' => self::DrawingsNameBn, 'updated_at' => now()]);
        if (DB::table('fund_expense_heads')->where('name_en', self::DrawingsName)->doesntExist()) {
            DB::table('fund_expense_heads')->insert(['name_en' => self::DrawingsName, 'name_bn' => self::DrawingsNameBn, 'for_tours' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function down(): void
    {
        DB::table('fund_expense_heads')->where('name_en', self::DrawingsName)->update(['name_en' => self::OldDrawingsName, 'updated_at' => now()]);
        Schema::table('policy_drafts', function (Blueprint $table): void {
            $table->dropColumn('self_approved');
        });
        Schema::table('financial_verifications', function (Blueprint $table): void {
            $table->dropColumn('fresh_start');
        });
    }
};
