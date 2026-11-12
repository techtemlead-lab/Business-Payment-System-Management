<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A medicine typed by hand (one that is not a company product) can carry its strength or
 * form, such as "500 mg tablet", beside its name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->string('strength', 80)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('prescription_lines', function (Blueprint $table): void {
            $table->dropColumn('strength');
        });
    }
};
