<?php

use App\Support\SiteCustomization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Until now a blank office hours box still showed built-in hours to Bangla visitors,
 * so a blank box here was never a choice to hide them. A blank box hides them from
 * now on, so the built-in hours are written into the box once. Hours the owner typed
 * are left alone.
 */
return new class extends Migration
{
    private const BuiltInHours = 'Sat - Thu: 9:00 AM - 8:00 PM';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(?)', [81416002]);

            $page = DB::table('content_pages')->where('slug', 'frontend_customization')->lockForUpdate()->first();
            $content = $page ? json_decode((string) $page->body, true) : null;
            if (! is_array($content) || trim((string) ($content['contact_phone_hours'] ?? '')) !== '') {
                return;
            }

            $content['contact_phone_hours'] = self::BuiltInHours;
            DB::table('content_pages')->where('id', $page->id)->update([
                'body' => json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }, 3);

        SiteCustomization::forgetCache();
    }

    /** The boxes filled here cannot be told apart from ones the owner filled. */
    public function down(): void {}
};
