<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An optional monthly budget per expense head, and how each head is read by the profit and
     * loss: most heads are expenses, but paying a manufacturer or an owner taking money out is not.
     */
    public function up(): void
    {
        Schema::table('fund_expense_heads', function (Blueprint $table): void {
            $table->string('report_as', 20)->default('expense');
        });
        DB::table('fund_expense_heads')->where('name_en', 'Payment to manufacturer')->update(['report_as' => 'supplier_payment']);
        DB::table('fund_expense_heads')->whereIn('name_en', ["Owner's drawings", 'Owner withdrawal'])->update(['report_as' => 'owner_withdrawal']);

        Schema::create('fund_head_budgets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expense_head_id')->constrained('fund_expense_heads')->cascadeOnDelete();
            $table->date('month');
            $table->decimal('amount', 14, 2);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['expense_head_id', 'month']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fund_head_budgets');
        Schema::table('fund_expense_heads', function (Blueprint $table): void {
            $table->dropColumn('report_as');
        });
    }
};
