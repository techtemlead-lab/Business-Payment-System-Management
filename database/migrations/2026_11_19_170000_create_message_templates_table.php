<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner-edited SMS and email texts. A row only overrides the default written in code
 * (lang files); deleting the row brings the default back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 80);
            $table->string('channel', 5);
            $table->string('locale', 5);
            $table->string('subject', 200)->nullable();
            $table->text('body');
            $table->boolean('active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['key', 'channel', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
