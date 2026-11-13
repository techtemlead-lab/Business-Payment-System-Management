<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A prescription is more than medicines: the tests the patient should have done, and the
 * foods to eat and to avoid. Each column holds an encrypted list, like the other clinical text.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->text('investigations')->nullable();
            $table->text('foods_allowed')->nullable();
            $table->text('foods_avoided')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table): void {
            $table->dropColumn(['investigations', 'foods_allowed', 'foods_avoided']);
        });
    }
};
