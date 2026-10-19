<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Collapse duplicate pending payment claim references before uniqueness.
        $duplicates = DB::table('payment_claims')
            ->select('channel', 'reference', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('reference')
            ->where('reference', '!=', '')
            ->groupBy('channel', 'reference')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $duplicate) {
            DB::table('payment_claims')
                ->where('channel', $duplicate->channel)
                ->where('reference', $duplicate->reference)
                ->where('id', '!=', $duplicate->keep_id)
                ->whereNotIn('id', DB::table('receipts')->select('claim_id'))
                ->delete();
        }

        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->unique(['channel', 'reference'], 'payment_claims_channel_reference_unique');
            $table->index(['order_id', 'status'], 'payment_claims_order_status_index');
        });

        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE UNIQUE INDEX users_phone_login_lookup ON users ((CASE WHEN regexp_replace(phone, '[^0-9]', '', 'g') LIKE '00%' THEN substr(regexp_replace(phone, '[^0-9]', '', 'g'), 3) WHEN char_length(regexp_replace(phone, '[^0-9]', '', 'g')) = 11 AND regexp_replace(phone, '[^0-9]', '', 'g') LIKE '01%' THEN '88' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(phone, '[^0-9]', '', 'g') END)) WHERE phone IS NOT NULL AND regexp_replace(phone, '[^0-9]', '', 'g') <> ''");

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->index(['action', 'created_at'], 'audit_events_action_created_at_index');
        });

        Schema::table('enquiries', function (Blueprint $table): void {
            $table->index('status', 'enquiries_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('enquiries', function (Blueprint $table): void {
            $table->dropIndex('enquiries_status_index');
        });
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropIndex('audit_events_action_created_at_index');
        });

        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE INDEX users_phone_login_lookup ON users ((CASE WHEN regexp_replace(phone, '[^0-9]', '', 'g') LIKE '00%' THEN substr(regexp_replace(phone, '[^0-9]', '', 'g'), 3) WHEN char_length(regexp_replace(phone, '[^0-9]', '', 'g')) = 11 AND regexp_replace(phone, '[^0-9]', '', 'g') LIKE '01%' THEN '88' || regexp_replace(phone, '[^0-9]', '', 'g') ELSE regexp_replace(phone, '[^0-9]', '', 'g') END)) WHERE phone IS NOT NULL");

        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->dropIndex('payment_claims_order_status_index');
            $table->dropUnique('payment_claims_channel_reference_unique');
        });
    }
};
