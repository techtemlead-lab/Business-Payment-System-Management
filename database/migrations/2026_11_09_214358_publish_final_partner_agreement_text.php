<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The owner approved a professional partner agreement to replace the draft that was
 * seeded as version 1. The text lives in resources/agreements/partner-agreement-v1.*.txt,
 * which is also what a new install starts from, so a new install has nothing to do here.
 *
 * Published versions are read-only and an agreement partners accepted stays as it was,
 * so this adds the next version. It adds one only while the latest version is still the
 * seeded draft: a version an owner published is never replaced, whatever its words are.
 */
return new class extends Migration
{
    private const DraftNoteBn = 'খসড়া — আইনজীবী দিয়ে যাচাই করুন';

    private const DraftNoteEn = 'Draft — have a lawyer check this';

    public function up(): void
    {
        $latest = DB::table('partner_agreement_versions')->orderByDesc('version')->first();
        if ($latest === null || $latest->published_by !== null) {
            return;
        }
        if (! str_starts_with(trim($latest->body_bn), self::DraftNoteBn) || ! str_starts_with(trim($latest->body_en), self::DraftNoteEn)) {
            return;
        }

        $now = now();
        DB::table('partner_agreement_versions')->insert([
            'version' => (int) $latest->version + 1,
            'body_bn' => trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.bn.txt'))),
            'body_en' => trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.en.txt'))),
            'published_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        // Published versions are read-only, and partners may have accepted this one.
    }
};
