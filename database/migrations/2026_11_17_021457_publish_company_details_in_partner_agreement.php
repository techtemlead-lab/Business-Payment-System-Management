<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The default partner agreement left the company's own details blank. The owner asked for them
 * to be filled from the documents already supplied, and the owner can change them later by
 * publishing a new version. The text lives in resources/agreements/partner-agreement-v1.*.txt,
 * which is also what a new install starts from, so a new install has nothing to do here.
 *
 * Published versions are read-only and an agreement partners accepted stays as it was, so this
 * adds the next version. It adds one only while the latest version is still one the system
 * published with the legal name blank: a version an owner published is never replaced.
 */
return new class extends Migration
{
    private const BlankNameEn = '[Company to complete: registered legal name of the company]';

    private const BlankNameBn = '[কোম্পানি পূরণ করবে: কোম্পানির নিবন্ধিত আইনি নাম]';

    public function up(): void
    {
        $latest = DB::table('partner_agreement_versions')->orderByDesc('version')->first();
        if ($latest === null || $latest->published_by !== null) {
            return;
        }
        if (! str_contains($latest->body_en, self::BlankNameEn) || ! str_contains($latest->body_bn, self::BlankNameBn)) {
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
