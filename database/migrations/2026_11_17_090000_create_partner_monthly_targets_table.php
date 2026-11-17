<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The owner's own target for one partner in one month; without a row the company's default rule is used.
        Schema::create('partner_monthly_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('month', 7);
            $table->string('kind', 20);
            $table->decimal('amount', 14, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('set_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'month', 'kind']);
            $table->index('month');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_monthly_targets');
    }
};
