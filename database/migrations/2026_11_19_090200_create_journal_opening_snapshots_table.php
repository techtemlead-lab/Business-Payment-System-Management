<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per account for each month start: the running debit and credit total of every journal line
 * posted before that moment. The trial balance reads the newest snapshot before the period and adds
 * only the lines since, instead of summing the whole journal from the first day at every view (PQ-05).
 * The journal is append-only, so a snapshot of a finished month never changes. It is only a speed-up:
 * the trial balance gives the same figures with or without it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_opening_snapshots', function (Blueprint $table): void {
            $table->timestamp('as_of');
            $table->string('account');
            $table->decimal('debit', 16, 2)->default(0);
            $table->decimal('credit', 16, 2)->default(0);
            $table->timestamp('built_at')->useCurrent();
            $table->primary(['as_of', 'account']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_opening_snapshots');
    }
};
