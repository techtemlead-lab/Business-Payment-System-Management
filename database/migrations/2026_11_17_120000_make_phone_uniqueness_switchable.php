<?php

use App\Support\PhoneIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One mobile number for one account is now an owner switch (off by default, owner's word
 * 2026-11-17), so the database may no longer refuse a second account on the same number.
 *
 * The unique index becomes a plain index on the same expression: phone sign-in stays fast, and
 * while the owner switch is on the application refuses the second account instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        $expression = PhoneIdentity::SQL;
        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE INDEX users_phone_login_lookup ON users (({$expression})) WHERE phone IS NOT NULL AND regexp_replace(phone, '[^0-9]', '', 'g') <> ''");
    }

    /** Fails on purpose when two accounts now share a number: that data cannot go back under a unique index. */
    public function down(): void
    {
        $expression = PhoneIdentity::SQL;
        DB::statement('DROP INDEX IF EXISTS users_phone_login_lookup');
        DB::statement("CREATE UNIQUE INDEX users_phone_login_lookup ON users (({$expression})) WHERE phone IS NOT NULL AND regexp_replace(phone, '[^0-9]', '', 'g') <> '' AND phone_share_approved_by IS NULL");
    }
};
