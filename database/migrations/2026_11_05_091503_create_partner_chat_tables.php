<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One chat per dealer or sub-dealer with the company office. Partners can only
 * write to the office; there is no partner-to-partner chat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_conversations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('partner_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_message_at')->nullable()->index();
            $table->string('last_message_preview', 160)->nullable();
            $table->string('last_sender_side', 10)->nullable();
            $table->unsignedInteger('partner_unread')->default(0);
            $table->unsignedInteger('office_unread')->default(0);
            $table->timestamps();
        });

        Schema::create('partner_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->constrained('partner_conversations')->cascadeOnDelete();
            $table->foreignId('sender_id')->constrained('users');
            $table->string('sender_side', 10);
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_mime', 60)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_messages');
        Schema::dropIfExists('partner_conversations');
    }
};
