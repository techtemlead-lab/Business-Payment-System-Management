<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for columns the portal pages filter or sort on that had none.
 * PostgreSQL does not index foreign keys on its own, so order_lines.product_id
 * and users.sponsor_id were scanned in full.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Staff order lists sort by newest first; dashboards count today's orders and the 14-day trend.
        Schema::table('orders', function (Blueprint $table): void {
            $table->index('created_at');
        });

        // Product edit checks for order history; deleting a product checks the foreign key.
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->index('product_id');
        });

        // Payments queue status filter and the "payments to check" count on the owner dashboard.
        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->index('status');
        });

        // Withdrawal requests badge counted on every staff page.
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->index('status');
        });

        // Direct downline lookups for partner milestones and the network screens.
        Schema::table('users', function (Blueprint $table): void {
            $table->index('sponsor_id');
        });

        // Unread notice badge counted on every portal page.
        Schema::table('account_notices', function (Blueprint $table): void {
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('account_notices', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'read_at']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['sponsor_id']);
        });

        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->dropIndex(['status']);
        });

        Schema::table('payment_claims', function (Blueprint $table): void {
            $table->dropIndex(['status']);
        });

        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropIndex(['product_id']);
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });
    }
};
