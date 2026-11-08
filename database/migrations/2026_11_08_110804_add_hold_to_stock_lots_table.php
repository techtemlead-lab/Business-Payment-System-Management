<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A lot on hold stays on the books but cannot go out to anyone until it is released
     * or written off. All nullable: no existing lot is on hold.
     */
    public function up(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->timestamp('held_at')->nullable();
            $table->foreignId('held_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('hold_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('stock_lots', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('held_by');
            $table->dropColumn(['held_at', 'hold_reason']);
        });
    }
};
