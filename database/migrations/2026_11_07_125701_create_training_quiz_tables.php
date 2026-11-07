<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Short multiple-choice quizzes on training items, and each partner's result.
 * A partner who reaches the item's pass mark has completed it and can print a certificate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('training_items', function (Blueprint $table): void {
            $table->unsignedSmallInteger('quiz_pass_percent')->default(70);
        });

        Schema::create('training_quiz_questions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->string('question', 500);
            $table->json('options');
            $table->unsignedSmallInteger('correct_option');
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['training_item_id', 'sort_order']);
        });

        Schema::create('training_completions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('training_item_id')->constrained('training_items')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedSmallInteger('last_score')->default(0);
            $table->unsignedSmallInteger('best_score')->default(0);
            $table->timestamp('passed_at')->nullable();
            $table->timestamps();

            $table->unique(['training_item_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_completions');
        Schema::dropIfExists('training_quiz_questions');
        Schema::table('training_items', function (Blueprint $table): void {
            $table->dropColumn('quiz_pass_percent');
        });
    }
};
