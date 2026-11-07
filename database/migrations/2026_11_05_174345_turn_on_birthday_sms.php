<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner turned birthday SMS on (2026-11-05). Where dashboard settings were already saved,
 * switch it on there too; the greeting still only goes out once a real SMS provider is set up.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->setBirthdaySms(true);
    }

    public function down(): void
    {
        $this->setBirthdaySms(false);
    }

    private function setBirthdaySms(bool $enabled): void
    {
        $row = DB::table('content_pages')->where('slug', 'dashboard_management')->first();
        if (! $row || empty($row->body)) {
            return;
        }
        $settings = json_decode((string) $row->body, true);
        if (! is_array($settings)) {
            return;
        }
        $settings['birthday_sms_enabled'] = $enabled;
        DB::table('content_pages')->where('slug', 'dashboard_management')->update([
            'body' => json_encode($settings, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ]);
    }
};
