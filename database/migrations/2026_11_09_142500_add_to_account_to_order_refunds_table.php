<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How much of a refund went straight back to the partner's own account.
     * The rest was given back by hand, as every refund was before.
     */
    public function up(): void
    {
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->decimal('to_account', 14, 2)->default(0);
        });
        DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_to_account_check CHECK (to_account >= 0 AND to_account <= amount)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_refunds DROP CONSTRAINT order_refunds_to_account_check');
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropColumn('to_account');
        });
    }
};
