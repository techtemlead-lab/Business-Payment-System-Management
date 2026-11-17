<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two more optional product facts: the group a product belongs to (for example Paracetamol)
 * and what it is known to help with. Both stay blank until the owner fills them in.
 * Warnings already exist (warnings_bn / warnings_en).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->string('group_bn', 120)->nullable();
            $table->string('group_en', 120)->nullable();
            $table->text('efficacy_bn')->nullable();
            $table->text('efficacy_en')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn(['group_bn', 'group_en', 'efficacy_bn', 'efficacy_en']);
        });
    }
};
