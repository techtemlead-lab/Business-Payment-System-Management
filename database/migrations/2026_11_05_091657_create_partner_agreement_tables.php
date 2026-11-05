<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dealer agreement partners accept on screen. Every published version is
 * kept for good, and every acceptance remembers who, which version, when, and
 * from which IP address and device.
 *
 * Version 1 is the draft written in resources/agreements/. The owner edits it
 * in the owner panel, which publishes version 2, 3, and so on. Published
 * versions can never be changed or removed, the same protection as the ledger.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_agreement_versions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('version')->unique();
            $table->text('body_bn');
            $table->text('body_en');
            $table->foreignId('published_by')->nullable()->constrained('users');
            $table->timestamp('published_at');
            $table->timestamps();
        });

        Schema::create('partner_agreement_acceptances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->foreignId('partner_agreement_version_id')->constrained('partner_agreement_versions');
            $table->timestamp('accepted_at');
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'partner_agreement_version_id']);
            $table->index('partner_agreement_version_id');
        });

        $now = now();
        DB::table('partner_agreement_versions')->insert([
            'version' => 1,
            'body_bn' => trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.bn.txt'))),
            'body_en' => trim((string) file_get_contents(resource_path('agreements/partner-agreement-v1.en.txt'))),
            'published_by' => null,
            'published_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION madina_reject_agreement_version_change()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'partner agreement versions are read-only: publish a new version instead';
END;
$$;

DROP TRIGGER IF EXISTS partner_agreement_versions_read_only ON partner_agreement_versions;

CREATE TRIGGER partner_agreement_versions_read_only
    BEFORE UPDATE OR DELETE ON partner_agreement_versions
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_agreement_version_change();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_agreement_acceptances');
        Schema::dropIfExists('partner_agreement_versions');
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared('DROP FUNCTION IF EXISTS madina_reject_agreement_version_change();');
        }
    }
};
