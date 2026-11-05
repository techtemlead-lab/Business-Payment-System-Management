<?php

use App\Support\PhoneIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every account needs its own mobile number (owner decision, 2026-11-05). Only an owner may let
 * one account use a number another account already has; who allowed it and why is kept here
 * (and in the audit log) so the go-live check can tell approved shared numbers from mistakes.
 *
 * The database still refuses two unapproved accounts on one number: the unique index now skips
 * only accounts an owner approved. A plain index on the same expression keeps phone sign-in fast.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('phone_share_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('phone_share_reason')->nullable();
        });

        $expression = PhoneIdentity::SQL;
        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE UNIQUE INDEX users_phone_login_lookup ON users (({$expression})) WHERE phone IS NOT NULL AND regexp_replace(phone, '[^0-9]', '', 'g') <> '' AND phone_share_approved_by IS NULL");
        DB::statement("CREATE INDEX users_phone_login_identity ON users (({$expression})) WHERE phone IS NOT NULL");
    }

    public function down(): void
    {
        $expression = PhoneIdentity::SQL;
        DB::statement('DROP INDEX IF EXISTS users_phone_login_identity');
        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE UNIQUE INDEX users_phone_login_lookup ON users (({$expression})) WHERE phone IS NOT NULL AND regexp_replace(phone, '[^0-9]', '', 'g') <> ''");

        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('phone_share_approved_by');
            $table->dropColumn('phone_share_reason');
        });
    }
};
