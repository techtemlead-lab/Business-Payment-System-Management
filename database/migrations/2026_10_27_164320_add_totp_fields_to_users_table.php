<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('totp_secret')->nullable()->after('remember_token');
            $table->timestamp('totp_confirmed_at')->nullable()->after('totp_secret');
            $table->text('totp_recovery_codes')->nullable()->after('totp_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['totp_secret', 'totp_confirmed_at', 'totp_recovery_codes']);
        });
    }
};
