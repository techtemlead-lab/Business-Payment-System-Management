<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Problems the site hit while running, one row per kind of problem (class, file and line).
 * It keeps a short message and where it happened, never what the visitor sent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint', 40)->unique();
            $table->string('exception_class', 200);
            $table->string('message', 500);
            $table->string('file', 300);
            $table->unsignedInteger('line');
            $table->string('portal', 20)->nullable();
            $table->string('route_name', 120)->nullable();
            $table->unsignedSmallInteger('http_status')->default(500);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedInteger('occurrences')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at')->index();
            $table->timestamp('fixed_at')->nullable();
            $table->unsignedBigInteger('fixed_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_reports');
    }
};
