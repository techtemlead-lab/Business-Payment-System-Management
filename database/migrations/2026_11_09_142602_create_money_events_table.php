<?php

use App\Domain\Finance\EventLogProjector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The Treasury event log: one read-only row for every order, payment, commission, fund movement,
 * withdrawal and stock movement. It is filled from the existing records by
 * {@see EventLogProjector}, can be emptied and rebuilt at any time and is never
 * used to decide anything.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('money_events', function (Blueprint $table): void {
            $table->id();
            $table->timestampTz('occurred_at');
            $table->string('kind', 20);
            $table->string('action', 40);
            $table->string('source', 30);
            $table->string('source_id', 64);
            $table->smallInteger('part')->default(0);
            $table->uuid('order_id')->nullable();
            $table->string('order_number', 40)->nullable();
            $table->unsignedBigInteger('partner_id')->nullable();
            $table->string('partner_name')->nullable();
            $table->string('partner_code')->nullable();
            $table->unsignedBigInteger('recipient_id')->nullable();
            $table->string('recipient_name')->nullable();
            $table->string('fund_key', 30)->nullable();
            $table->string('label', 60)->nullable();
            $table->decimal('percent', 6, 2)->nullable();
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('product_name')->nullable();
            $table->unsignedBigInteger('branch_id')->nullable();
            $table->integer('quantity')->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('audience', 10)->default('all');
            $table->timestampTz('created_at')->useCurrent();
            $table->unique(['source', 'source_id', 'part']);
            $table->index('order_number');
        });
        Schema::create('money_event_watermarks', function (Blueprint $table): void {
            $table->string('feed', 40)->primary();
            $table->timestampTz('seen_until');
            $table->timestampTz('updated_at')->useCurrent();
        });
        DB::statement('CREATE INDEX money_events_time_index ON money_events (occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX money_events_kind_time_index ON money_events (kind, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX money_events_action_time_index ON money_events (action, occurred_at DESC, id DESC)');
        DB::statement('CREATE INDEX money_events_fund_time_index ON money_events (fund_key, occurred_at DESC) WHERE fund_key IS NOT NULL');
        DB::statement('CREATE INDEX money_events_partner_time_index ON money_events (partner_id, occurred_at DESC) WHERE partner_id IS NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('money_event_watermarks');
        Schema::dropIfExists('money_events');
    }
};
