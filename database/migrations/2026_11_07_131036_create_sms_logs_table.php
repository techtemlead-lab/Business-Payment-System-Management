<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per SMS the system tried to send through a gateway. The message text is
     * never kept (it can hold a login code); only the masked number and the result.
     * Rows are never deleted automatically: the owner deletes old ones on the Data cleanup page.
     */
    public function up(): void
    {
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('phone_masked', 32);
            $table->string('provider', 40)->nullable();
            $table->boolean('sent')->default(false);
            $table->unsignedInteger('length')->default(0);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
