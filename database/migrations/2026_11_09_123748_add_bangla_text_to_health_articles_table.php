<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Health articles get a Bangla title and text beside the existing ones.
 * An article may now be written in one language only, so the existing boxes can be empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_articles', function (Blueprint $table): void {
            $table->string('title', 200)->nullable()->change();
            $table->text('body')->nullable()->change();
            $table->string('title_bn', 200)->nullable();
            $table->text('body_bn')->nullable();
        });
    }

    public function down(): void
    {
        DB::table('health_articles')->whereNull('title')->update(['title' => DB::raw('title_bn')]);
        DB::table('health_articles')->whereNull('body')->update(['body' => DB::raw('body_bn')]);

        Schema::table('health_articles', function (Blueprint $table): void {
            $table->dropColumn(['title_bn', 'body_bn']);
            $table->string('title', 200)->nullable(false)->change();
            $table->text('body')->nullable(false)->change();
        });
    }
};
