<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Owners may read back dealer and sub-dealer passwords only (owner decision, 2026-11-17). Readable
 * copies kept for staff, MD, Chairman and Super Admin accounts are wiped: those people keep their
 * passwords and sign in as before; an owner can still reset them, but can no longer read them.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->whereNotIn('role', ['dealer', 'sub_dealer'])
            ->whereNotNull('admin_password_cipher')
            ->update(['admin_password_cipher' => null]);
    }

    /** A wiped copy cannot be brought back; nothing to undo. */
    public function down(): void {}
};
