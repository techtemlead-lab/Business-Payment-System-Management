<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cancel, return and refund requests on orders.
     *
     * - `order_lines.closed`: units cancelled before they were sent (they never leave the shelf).
     * - `order_lines.returned`: delivered units the partner gave back.
     * - `order_return_requests`: the partner's or the desk's request, its approval by one or two
     *   people, and what was done. Nothing moves until the request is carried out.
     * - `order_refunds` may now hold more than one refund per order (a later return after an
     *   earlier cancel), and records the cash part that was put in the partner's account and the
     *   second person who approved it.
     */
    public function up(): void
    {
        Schema::table('order_lines', function (Blueprint $table) {
            $table->unsignedInteger('closed')->default(0);
            $table->unsignedInteger('returned')->default(0);
        });
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_closed_check CHECK (closed >= 0 AND dispatched + closed <= quantity)');
        DB::statement('ALTER TABLE order_lines ADD CONSTRAINT order_lines_returned_check CHECK (returned >= 0 AND returned <= dispatched)');

        Schema::create('order_return_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_id')->constrained();
            $table->foreignId('user_id')->index()->constrained();
            $table->string('kind', 20);
            $table->string('status', 20)->default('requested');
            $table->json('lines');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users');
            $table->decimal('refund_amount', 14, 2)->nullable();
            $table->string('refund_method', 20)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained('company_branches');
            $table->foreignId('first_approved_by')->nullable()->constrained('users');
            $table->timestamp('first_approved_at')->nullable();
            $table->text('first_approval_note')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->uuid('order_refund_id')->nullable();
            $table->timestamps();
            $table->index(['status', 'created_at']);
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE order_return_requests ADD CONSTRAINT order_return_requests_kind_check CHECK (kind IN ('cancel', 'return', 'refund'))");
        DB::statement("ALTER TABLE order_return_requests ADD CONSTRAINT order_return_requests_status_check CHECK (status IN ('requested', 'awaiting_second', 'done', 'rejected', 'withdrawn'))");
        DB::statement("ALTER TABLE order_return_requests ADD CONSTRAINT order_return_requests_method_check CHECK (refund_method IS NULL OR refund_method IN ('account', 'cash'))");
        DB::statement('ALTER TABLE order_return_requests ADD CONSTRAINT order_return_requests_amount_check CHECK (refund_amount IS NULL OR refund_amount >= 0)');
        // One open request per order, so two desks cannot move the same goods or money twice.
        DB::statement("CREATE UNIQUE INDEX order_return_requests_one_open ON order_return_requests (order_id) WHERE status IN ('requested', 'awaiting_second')");

        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->index('order_id');
            $table->string('kind', 20)->default('refund');
            $table->decimal('cash_to_account', 14, 2)->default(0);
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->foreignId('request_id')->nullable()->constrained('order_return_requests');
        });
        DB::statement('ALTER TABLE order_refunds ADD CONSTRAINT order_refunds_cash_to_account_check CHECK (cash_to_account >= 0 AND to_account + cash_to_account <= amount)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE order_refunds DROP CONSTRAINT order_refunds_cash_to_account_check');
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->dropConstrainedForeignId('request_id');
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['kind', 'cash_to_account']);
            $table->dropIndex(['order_id']);
        });
        // Only safe while every order still has at most one refund.
        Schema::table('order_refunds', function (Blueprint $table) {
            $table->unique('order_id');
        });
        Schema::dropIfExists('order_return_requests');
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT order_lines_returned_check');
        DB::statement('ALTER TABLE order_lines DROP CONSTRAINT order_lines_closed_check');
        Schema::table('order_lines', function (Blueprint $table) {
            $table->dropColumn(['closed', 'returned']);
        });
    }
};
