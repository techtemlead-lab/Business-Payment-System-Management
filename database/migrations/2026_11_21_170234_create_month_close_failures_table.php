<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Month closes that failed and have not succeeded since, kept in the database so that clearing the cache can never
 * hide one (they were kept in the cache before). One row per kind and month; a later successful close deletes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('month_close_failures', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('month', 7);
            $table->string('error', 300);
            $table->timestamp('failed_at');
            $table->timestamps();
            $table->unique(['kind', 'month']);
        });
        // Bring over the failures the cache still holds, so none is lost on the way.
        foreach ((array) Cache::get('finance:month-close-failures', []) as $failure) {
            if (is_array($failure) && isset($failure['kind'], $failure['month'])) {
                DB::table('month_close_failures')->insertOrIgnore(['kind' => (string) $failure['kind'], 'month' => (string) $failure['month'],
                    'error' => mb_substr((string) ($failure['error'] ?? ''), 0, 300), 'failed_at' => $failure['failed_at'] ?? now(),
                    'created_at' => now(), 'updated_at' => now()]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('month_close_failures');
    }
};
