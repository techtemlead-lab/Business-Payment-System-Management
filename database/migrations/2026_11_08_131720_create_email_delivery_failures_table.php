<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per event email the mail server would not take. The message text is never
     * kept, only the event, a masked address and the short reason. Rows older than 90 days
     * are removed when a new failure is recorded (see EmailDeliveryLog).
     */
    public function up(): void
    {
        Schema::create('email_delivery_failures', function (Blueprint $table) {
            $table->id();
            $table->string('event', 60);
            $table->string('recipient_masked', 200);
            $table->string('reason', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_delivery_failures');
    }
};
