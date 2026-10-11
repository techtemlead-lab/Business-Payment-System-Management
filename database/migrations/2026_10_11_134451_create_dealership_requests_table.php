<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dealership_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 160);
            $table->string('mobile', 20);
            $table->string('business_name', 160);
            $table->text('business_address');
            $table->string('level', 30);
            $table->string('division', 100);
            $table->string('district', 100)->nullable();
            $table->string('upazila', 100)->nullable();
            $table->string('union_name', 100)->nullable();
            $table->text('message')->nullable();
            $table->enum('status', ['pending', 'reviewed', 'declined'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();
            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dealership_requests');
    }
};
