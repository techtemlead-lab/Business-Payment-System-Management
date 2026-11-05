<?php

use App\Domain\Geography\PlaceCatalogRestorer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The November 3 catalog clear keeps places already linked to a branch,
 * dealer area, or partner profile, and the name restore then skips because
 * the table is not empty. Reload the missing names and parent links here.
 * Tests build their own small place fixtures, so this stays out of that path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing') || ! Schema::hasTable('place_codes')) {
            return;
        }

        app(PlaceCatalogRestorer::class)->restoreFromCatalogFile();
    }

    public function down(): void
    {
        // Place names are reference data. Rolling back must not delete them.
    }
};
