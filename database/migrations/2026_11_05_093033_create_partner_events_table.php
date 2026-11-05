<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Meetings and training sessions for partners. Times are stored in UTC and
     * entered and shown in Asia/Dhaka.
     */
    public function up(): void
    {
        Schema::create('partner_events', function (Blueprint $table) {
            $table->id();
            $table->string('title_bn', 200);
            $table->string('title_en', 200);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->string('place', 300)->nullable();
            $table->string('online_url', 500)->nullable();
            $table->string('audience', 16)->default('all');
            $table->jsonb('divisions')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('draft');
            $table->boolean('send_sms')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'starts_at']);
        });

        DB::statement("ALTER TABLE partner_events ADD CONSTRAINT partner_events_status_check CHECK (status IN ('draft', 'published', 'cancelled'))");
        DB::statement("ALTER TABLE partner_events ADD CONSTRAINT partner_events_audience_check CHECK (audience IN ('all', 'divisions'))");
        DB::statement('ALTER TABLE partner_events ADD CONSTRAINT partner_events_time_check CHECK (ends_at > starts_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_events');
    }
};
