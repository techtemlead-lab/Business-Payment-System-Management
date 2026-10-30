<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_feedback', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('role')->default('Verified Client');
            $table->string('location')->default('Bangladesh');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->text('message');
            $table->string('source', 32)->default('public');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 24)->default('pending')->index();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['source', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_feedback');
    }
};
