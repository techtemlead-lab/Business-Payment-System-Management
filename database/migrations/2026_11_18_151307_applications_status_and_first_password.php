<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dealer_applications', function (Blueprint $table): void {
            // The number the applicant keeps to look up the result; also printed on the office screen.
            $table->string('application_number', 20)->nullable()->unique();
            // Why the office sent the application back for correction.
            $table->text('correction_note')->nullable();
        });
        DB::statement("UPDATE dealer_applications SET application_number = 'APP-' || UPPER(SUBSTR(REPLACE(id::text, '-', ''), 1, 8)) WHERE application_number IS NULL");

        Schema::table('partner_conversations', function (Blueprint $table): void {
            // When the oldest partner message nobody has answered yet was sent; empty once the office replies.
            $table->timestamp('awaiting_since')->nullable()->index();
        });
        DB::statement("UPDATE partner_conversations c SET awaiting_since = (
            SELECT MIN(m.created_at) FROM partner_messages m
            WHERE m.conversation_id = c.id AND m.sender_side = 'partner'
              AND m.id > COALESCE((SELECT MAX(o.id) FROM partner_messages o WHERE o.conversation_id = c.id AND o.sender_side = 'office'), 0)
        )");

        Schema::table('users', function (Blueprint $table): void {
            // A set-up code or temporary password was handed out: the person must choose their own at first sign-in.
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('must_change_password'));
        Schema::table('partner_conversations', fn (Blueprint $table) => $table->dropColumn('awaiting_since'));
        Schema::table('dealer_applications', fn (Blueprint $table) => $table->dropColumn(['application_number', 'correction_note']));
    }
};
