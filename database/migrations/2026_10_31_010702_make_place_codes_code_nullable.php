<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('place_codes', function (Blueprint $table): void {
            $table->dropUnique(['code']);
        });

        Schema::table('place_codes', function (Blueprint $table): void {
            $table->string('code', 32)->nullable()->change();
        });

        Schema::table('place_codes', function (Blueprint $table): void {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('place_codes', function (Blueprint $table): void {
            $table->dropUnique(['code']);
        });

        Schema::table('place_codes', function (Blueprint $table): void {
            $table->string('code', 32)->nullable(false)->change();
        });

        Schema::table('place_codes', function (Blueprint $table): void {
            $table->unique('code');
        });
    }
};
