<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fund_expense_heads', function (Blueprint $table): void {
            $table->id();
            $table->string('name_en', 120)->unique();
            $table->string('name_bn', 120);
            $table->boolean('is_active')->default(true);
            $table->boolean('for_tours')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::table('reserve_disbursements', function (Blueprint $table): void {
            $table->string('fund_key', 40)->nullable();
            $table->foreignId('expense_head_id')->nullable()->constrained('fund_expense_heads');
            $table->date('spent_on')->nullable();
            $table->string('payment_method', 20)->nullable();
            $table->index(['fund_key', 'status']);
            $table->index('spent_on');
        });
        $now = now();
        DB::table('fund_expense_heads')->insert(array_map(
            fn (array $head): array => ['name_en' => $head[0], 'name_bn' => $head[1], 'for_tours' => $head[2] ?? false, 'created_at' => $now, 'updated_at' => $now],
            [
                ['Office rent', 'অফিস ভাড়া'],
                ['Staff salary and bonus', 'কর্মীদের বেতন ও বোনাস'],
                ['Electricity, water and other bills', 'বিদ্যুৎ, পানি ও অন্যান্য বিল'],
                ['Transport and delivery', 'যাতায়াত ও পরিবহন'],
                ['Advertising and promotion', 'বিজ্ঞাপন ও প্রচার'],
                ['Office supplies', 'অফিসের জিনিসপত্র'],
                ['Repairs and maintenance', 'মেরামত ও রক্ষণাবেক্ষণ'],
                ['Bank and service charges', 'ব্যাংক ও সার্ভিস চার্জ'],
                ['Tax and government fees', 'কর ও সরকারি ফি'],
                ['Payment to manufacturer', 'প্রস্তুতকারকের পাওনা পরিশোধ'],
                ['Clinic expenses', 'ক্লিনিকের খরচ'],
                ['Company tour', 'কোম্পানির ট্যুর', true],
                ['Owner withdrawal', 'মালিকের উত্তোলন'],
                ['Other', 'অন্যান্য'],
            ],
        ));
    }

    public function down(): void
    {
        Schema::table('reserve_disbursements', function (Blueprint $table): void {
            $table->dropIndex(['fund_key', 'status']);
            $table->dropIndex(['spent_on']);
            $table->dropConstrainedForeignId('expense_head_id');
            $table->dropColumn(['fund_key', 'spent_on', 'payment_method']);
        });
        Schema::dropIfExists('fund_expense_heads');
    }
};
