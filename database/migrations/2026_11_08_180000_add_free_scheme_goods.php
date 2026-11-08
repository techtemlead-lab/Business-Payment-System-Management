<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A dated scheme can now give goods for free. The free goods are an ordinary order line at
     * price zero, flagged so that no commission, cashback, pool, company share or funding check
     * ever counts them. The database itself refuses a free line that carries a price.
     */
    public function up(): void
    {
        Schema::table('dated_schemes', function (Blueprint $table): void {
            $table->foreignId('free_product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedInteger('free_quantity')->nullable();
        });
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->boolean('is_free')->default(false);
            $table->foreignId('dated_scheme_id')->nullable()->constrained('dated_schemes')->nullOnDelete();
            $table->string('scheme_title', 150)->nullable();
        });
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_free_line_has_no_price CHECK (NOT is_free OR (unit_price = 0 AND total = 0))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT IF EXISTS order_lines_free_line_has_no_price');
        Schema::table('order_lines', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('dated_scheme_id');
            $table->dropColumn(['is_free', 'scheme_title']);
        });
        Schema::table('dated_schemes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('free_product_id');
            $table->dropColumn('free_quantity');
        });
    }
};
