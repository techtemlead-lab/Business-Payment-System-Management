<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('identity_review_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('kind', 20);
            $table->char('match_hash', 64);
            $table->jsonb('user_ids');
            $table->jsonb('via');
            $table->string('status', 12)->default('open');
            $table->string('review_note', 300)->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamps();
            $table->unique(['kind', 'match_hash']);
            $table->index(['status', 'last_seen_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_review_flags');
    }
};
