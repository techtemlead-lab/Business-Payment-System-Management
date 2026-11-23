<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Several accounts may share an email address while the owner's "one person, one account" switch is off, which is
     * its starting state (owner, 2026-11-22). The database no longer insists that an email is unique; the application
     * enforces it only when the switch is on (see IdentityKeys). A plain index keeps email lookups fast. Signing in by an
     * email that more than one account has picks no account at random: those people sign in with their partner code
     * or phone, as with a shared phone number.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique('users_email_unique');
            $table->index('email', 'users_email_index');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex('users_email_index');
            $table->unique('email', 'users_email_unique');
        });
    }
};
