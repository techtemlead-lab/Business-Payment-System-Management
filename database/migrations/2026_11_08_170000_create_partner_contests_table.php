<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contests the owner defines and partners follow. Standings are worked out when read,
     * and prizes are given by hand, so nothing here touches commission or wallets.
     */
    public function up(): void
    {
        Schema::create('partner_contests', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 150);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('metric', 20);
            $table->string('prize_text', 500)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index('ends_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_contests');
    }
};
