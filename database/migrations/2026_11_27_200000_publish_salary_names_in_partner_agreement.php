<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The client asked (2026-11-27) for the partner agreement to use today's income names: the English
 * text still said "Dealer Acquisition Target Bonus" and "direct referral commission", and the Bangla
 * text still called the salary pool division-based. The corrected text lives in
 * resources/agreements/partner-agreement-v1.*.txt, which is also what a new install starts from.
 *
 * Published versions are read-only and an agreement partners accepted stays as it was, so this adds
 * the next version. It adds one only while the latest version is one the system published and its
 * text differs from the corrected files: a version an owner published is never replaced. While the
 * owner's "partners must accept" switch is on, partners accept the new version once on their next visit.
 */
return new class extends Migration
{
    public function up(): void
    {
        $latest = DB::table('partner_agreement_versions')->orderByDesc('version')->first();
        if ($latest === null || $latest->published_by !== null) {
            return;
        }

        $bodyBn = trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.bn.txt')));
        $bodyEn = trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.en.txt')));
        if (trim((string) $latest->body_bn) === $bodyBn && trim((string) $latest->body_en) === $bodyEn) {
            return;
        }

        $now = now();
        DB::table('partner_agreement_versions')->insert([
            'version' => (int) $latest->version + 1,
            'body_bn' => $bodyBn,
            'body_en' => $bodyEn,
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
