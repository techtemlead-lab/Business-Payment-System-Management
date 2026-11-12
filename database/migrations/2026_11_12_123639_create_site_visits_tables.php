<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The owner's visitor count for the public website. `site_visits` keeps one row for every page
 * opened and is emptied of rows older than a year; `site_visit_days` keeps one row of totals
 * for each business day and is never emptied. Neither holds a name, a phone or an IP address:
 * a visitor is only a random number kept in that browser's cookie.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_visits', function (Blueprint $table): void {
            $table->id();
            $table->string('visitor_id', 40);
            $table->string('path', 255);
            $table->string('source', 20);
            $table->string('device', 10);
            $table->date('visited_on');
            $table->timestampTz('created_at')->useCurrent();
            $table->index(['visitor_id', 'visited_on']);
            $table->index('visited_on');
            $table->index('created_at');
        });
        Schema::create('site_visit_days', function (Blueprint $table): void {
            $table->date('day')->primary();
            $table->unsignedInteger('views')->default(0);
            $table->unsignedInteger('visitors')->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_visit_days');
        Schema::dropIfExists('site_visits');
    }
};
