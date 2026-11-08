<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_list_filters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('list_key', 30);
            $table->string('name', 60);
            $table->jsonb('filters');
            $table->timestamps();
            $table->unique(['user_id', 'list_key', 'name']);
        });
        Schema::create('list_column_choices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('list_key', 30);
            $table->jsonb('hidden_columns');
            $table->timestamps();
            $table->unique(['user_id', 'list_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('list_column_choices');
        Schema::dropIfExists('saved_list_filters');
    }
};
