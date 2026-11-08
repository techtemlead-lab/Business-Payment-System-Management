<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A partner's own customer book: private to that partner, including from the owner.
     * There is deliberately no product or stock link.
     */
    public function up(): void
    {
        Schema::create('partner_customers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 150);
            $table->string('phone', 20)->nullable();
            $table->text('notes')->nullable();
            $table->date('follow_up_on')->nullable();
            $table->timestamps();
            $table->index(['partner_id', 'follow_up_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_customers');
    }
};
