<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('dealer')->index(); $t->string('status')->default('active')->index();
            $t->string('dealer_code')->nullable()->unique(); $t->string('phone')->nullable();
            $t->json('profile')->nullable(); $t->text('nid')->nullable();
            $t->foreignId('sponsor_id')->nullable()->constrained('users'); $t->boolean('directory_visible')->default(false);
            $t->string('district')->nullable(); $t->string('division')->nullable();
            $t->decimal('latitude',10,7)->nullable(); $t->decimal('longitude',10,7)->nullable();
        });
        Schema::create('products', function (Blueprint $t) {
            $t->id(); $t->string('sku')->unique(); $t->string('name'); $t->string('category');
            $t->text('description')->nullable(); $t->string('unit')->default('pack');
            $t->decimal('mrp',14,2); $t->decimal('dealer_price',14,2)->nullable();
            $t->integer('stock')->default(0); $t->integer('low_stock_at')->default(10);
            $t->boolean('active')->default(true); $t->string('tone')->default('sage'); $t->timestamps();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('number')->unique(); $t->foreignId('user_id')->constrained();
            $t->string('status')->default('submitted')->index(); $t->decimal('total',14,2); $t->decimal('paid',14,2)->default(0);
            $t->string('idempotency_key'); $t->string('payload_hash'); $t->text('note')->nullable(); $t->text('decision_reason')->nullable(); $t->timestamps();
            $t->unique(['user_id','idempotency_key']);
        });
        Schema::create('order_lines', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('order_id')->constrained(); $t->foreignId('product_id')->constrained();
            $t->string('name'); $t->string('sku'); $t->integer('quantity'); $t->integer('dispatched')->default(0);
            $t->decimal('mrp',14,2); $t->decimal('unit_price',14,2); $t->decimal('total',14,2); $t->timestamps();
        });
        Schema::create('payment_claims', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('order_id')->constrained(); $t->string('channel'); $t->string('reference');
            $t->decimal('amount',14,2); $t->string('status')->default('pending'); $t->text('reason')->nullable();
            $t->foreignId('verified_by')->nullable()->constrained('users'); $t->timestamps();
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('order_id')->constrained(); $t->foreignId('claim_id')->unique()->constrained('payment_claims');
            $t->string('channel'); $t->string('reference'); $t->decimal('amount',14,2); $t->timestamps(); $t->unique(['channel','reference']);
        });
        Schema::create('shipments', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('order_id')->constrained(); $t->string('courier'); $t->string('tracking');
            $t->date('eta')->nullable(); $t->string('status')->default('dispatched'); $t->json('lines');
            $t->foreignId('actor_id')->constrained('users'); $t->timestamps();
        });
        Schema::create('stock_movements', function (Blueprint $t) {
            $t->id(); $t->foreignId('product_id')->constrained(); $t->integer('quantity'); $t->string('kind');
            $t->string('reference')->unique(); $t->text('reason'); $t->foreignId('actor_id')->constrained('users'); $t->timestamps();
        });
        Schema::create('audit_events', function (Blueprint $t) {
            $t->id(); $t->foreignId('actor_id')->nullable()->constrained('users'); $t->string('action'); $t->string('subject');
            $t->json('metadata')->nullable(); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('policy_drafts', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('account_type'); $t->string('basis')->default('mrp');
            $t->json('components'); $t->text('notes')->nullable(); $t->string('status')->default('draft');
            $t->foreignId('created_by')->constrained('users'); $t->timestamps();
        });
        Schema::create('wallets', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->unique()->constrained(); $t->decimal('available',14,2)->default(0); $t->decimal('held',14,2)->default(0); $t->timestamps();
        });
        Schema::create('withdrawals', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->foreignId('user_id')->constrained(); $t->decimal('amount',14,2);
            $t->string('status')->default('requested'); $t->string('channel'); $t->text('destination'); $t->string('idempotency_key');
            $t->text('reason')->nullable(); $t->string('payment_reference')->nullable()->unique();
            $t->foreignId('reviewed_by')->nullable()->constrained('users'); $t->timestamps(); $t->unique(['user_id','idempotency_key']);
        });
        Schema::create('journal_entries', function (Blueprint $t) {
            $t->uuid('id')->primary(); $t->string('event_key')->unique(); $t->string('description');
            $t->foreignId('actor_id')->nullable()->constrained('users'); $t->timestamp('created_at')->useCurrent();
        });
        Schema::create('journal_lines', function (Blueprint $t) {
            $t->id(); $t->foreignUuid('entry_id')->constrained('journal_entries'); $t->foreignId('user_id')->nullable()->constrained();
            $t->string('account'); $t->decimal('debit',14,2)->default(0); $t->decimal('credit',14,2)->default(0);
        });
        Schema::create('content_pages', function (Blueprint $t) {
            $t->id(); $t->string('slug')->unique(); $t->string('title'); $t->text('body'); $t->boolean('published')->default(false); $t->timestamps();
        });
        Schema::create('enquiries', function (Blueprint $t) {
            $t->id(); $t->string('name'); $t->string('phone'); $t->string('topic'); $t->text('message'); $t->string('status')->default('new'); $t->timestamps();
        });
        Schema::create('account_notices', function (Blueprint $t) {
            $t->id(); $t->foreignId('user_id')->constrained(); $t->string('title'); $t->text('body'); $t->timestamp('read_at')->nullable(); $t->timestamps();
        });
        if (DB::getDriverName()==='pgsql') {
            DB::statement('ALTER TABLE products ADD CONSTRAINT stock_nonnegative CHECK (stock >= 0), ADD CONSTRAINT mrp_positive CHECK (mrp > 0)');
            DB::statement('ALTER TABLE order_lines ADD CONSTRAINT quantities_valid CHECK (quantity > 0 AND dispatched >= 0 AND dispatched <= quantity)');
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT funds_nonnegative CHECK (available >= 0 AND held >= 0)');
            DB::statement('ALTER TABLE orders ADD CONSTRAINT payment_bounds CHECK (paid >= 0 AND total > 0 AND paid <= total)');
        }
    }
    public function down(): void {
        foreach (['account_notices','enquiries','content_pages','journal_lines','journal_entries','withdrawals','wallets','policy_drafts','audit_events','stock_movements','shipments','receipts','payment_claims','order_lines','orders','products'] as $table) Schema::dropIfExists($table);
        Schema::table('users', fn(Blueprint $t)=>$t->dropColumn(['role','status','dealer_code','phone','profile','nid','sponsor_id','directory_visible','district','division','latitude','longitude']));
    }
};
