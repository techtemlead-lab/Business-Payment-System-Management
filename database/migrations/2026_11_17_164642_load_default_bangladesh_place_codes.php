<?php

use App\Domain\Geography\DefaultPlaceCatalog;
use Illuminate\Database\Migrations\Migration;

/**
 * Owner decision (2026-11-17): the full Bangladesh place list with its default codes is there by
 * default, so a deploy loads it. Fill-only: places, names and codes the office already set are
 * kept (see DefaultPlaceCatalog). Runs after the hyphen is dropped from stored codes.
 *
 * Skipped while the test suite builds its database: tests start with an empty place list and
 * load this one themselves when they need it, so every test does not pay for 5,400 rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        app(DefaultPlaceCatalog::class)->load();
    }

    /**
     * Nothing to undo: once loaded, places may carry dealers, branches and orders, and an added
     * place cannot be told apart from one the office made itself. Remove places in the panel.
     */
    public function down(): void {}
};
