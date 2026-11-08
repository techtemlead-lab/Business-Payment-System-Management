<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision (2026-11-08): birthday SMS stays off until the owner or responsible staff turn it on,
 * because each SMS costs money. Sites with no saved dashboard settings are already off through the default.
 */
return new class extends Migration
{
    public function up(): void
    {
        $row = DB::table('content_pages')->where('slug', 'dashboard_management')->first();
        if (! $row || empty($row->body)) {
            return;
        }
        $settings = json_decode((string) $row->body, true);
        if (! is_array($settings)) {
            return;
        }
        $settings['birthday_sms_enabled'] = false;
        DB::table('content_pages')->where('slug', 'dashboard_management')->update([
            'body' => json_encode($settings, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }

    /** Rolling back must never start paid SMS, so the switch is left as it is. */
    public function down(): void {}
};
