<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tells a product taken off sale from one that was never published. Products already
     * archived are found from the work log; every other hidden product stays a draft.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('active');
        });

        DB::statement("
            UPDATE products
            SET archived_at = last_event.created_at
            FROM (
                SELECT DISTINCT ON (subject) subject, action, created_at
                FROM audit_events
                WHERE action IN ('product.archived', 'product.restored')
                ORDER BY subject, created_at DESC, id DESC
            ) AS last_event
            WHERE products.active = false
              AND last_event.subject = products.id::text
              AND last_event.action = 'product.archived'
        ");
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
    }
};
