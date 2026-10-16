<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // 1. Directed Graph Closure Table for Referral Network
        Schema::create('referral_tree_paths', function (Blueprint $t) {
            $t->foreignId('ancestor_id')->constrained('users')->onDelete('cascade');
            $t->foreignId('descendant_id')->constrained('users')->onDelete('cascade');
            $t->integer('depth'); // 0 = self, 1 = Gen 1, 2 = Gen 2, 3 = Gen 3, ...
            $t->timestamps();
            $t->primary(['ancestor_id', 'descendant_id']);
            $t->index(['descendant_id', 'depth']);
            $t->index(['ancestor_id', 'depth']);
        });

        // 2. Dynamic Commission Rule Registry (100% parameterizable with versioning)
        Schema::create('commission_rule_parameters', function (Blueprint $t) {
            $t->id();
            $t->string('rule_key', 64)->unique();
            $t->string('category', 32)->index(); // area, referral, salary, cashback, rank, incentive, d2d, marketing, rewards
            $t->string('name', 128);
            $t->text('description')->nullable();
            $t->decimal('percentage', 5, 2)->default(0.00);
            $t->decimal('amount_threshold', 14, 2)->default(0.00);
            $t->decimal('cap_limit', 14, 2)->default(0.00);
            $t->json('parameters')->nullable();
            $t->boolean('is_active')->default(true);
            $t->integer('version')->default(1);
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
        });

        // 3. Dealer Ranks & Leadership Progression (MO, AM, SM, ME)
        Schema::create('dealer_ranks', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->unique()->constrained('users')->onDelete('cascade');
            $t->string('current_rank', 32)->default('dealer')->index(); // dealer, mo, am, sm, me
            $t->timestamp('achieved_at')->useCurrent();
            $t->boolean('one_time_incentive_paid')->default(false);
            $t->timestamp('one_time_incentive_paid_at')->nullable();
            $t->boolean('monthly_qualified')->default(false);
            $t->decimal('monthly_purchase_total', 14, 2)->default(0.00);
            $t->decimal('lifetime_purchase_total', 14, 2)->default(0.00);
            $t->json('qualification_history')->nullable();
            $t->timestamps();
        });

        // 4. Area Dealer Territorial Assignments & Quotas
        Schema::create('area_dealer_assignments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $t->string('level', 32)->index(); // division, district, upazila, union
            $t->string('zone_name', 128);
            $t->string('division', 64)->nullable()->index();
            $t->string('district', 64)->nullable()->index();
            $t->string('upazila', 64)->nullable()->index();
            $t->string('union', 64)->nullable()->index();
            $t->decimal('monthly_quota', 14, 2)->default(20000.00);
            $t->decimal('current_month_purchase', 14, 2)->default(0.00);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        // 5. Commission Allocations Ledger (Every slice allocated per order)
        Schema::create('commission_allocations', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('order_id')->constrained('orders')->onDelete('cascade');
            $t->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null'); // NULL = Company Fund
            $t->string('category', 64)->index();
            $t->decimal('rate_percentage', 5, 2);
            $t->decimal('cash_basis', 14, 2); // The collected cash amount from which this is calculated
            $t->decimal('amount', 14, 2);
            $t->string('status', 32)->default('available')->index(); // pending, available, settled, voided
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        // 6. Car & Bike Milestone Progress Tracking (with Anti-Whale Leg Capping)
        Schema::create('reward_milestone_progress', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $t->string('milestone_type', 32)->index(); // bike, car
            $t->decimal('target_amount', 14, 2); // 6,000,000 or 250,000,000
            $t->decimal('leg_cap_amount', 14, 2); // 800,000 or 20,000,000
            $t->decimal('raw_team_volume', 14, 2)->default(0.00);
            $t->decimal('qualifying_volume', 14, 2)->default(0.00);
            $t->boolean('achieved')->default(false)->index();
            $t->timestamp('achieved_at')->nullable();
            $t->boolean('claimed')->default(false);
            $t->timestamp('claimed_at')->nullable();
            $t->json('leg_breakdown')->nullable();
            $t->timestamps();
            $t->unique(['user_id', 'milestone_type']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE commission_rule_parameters ADD CONSTRAINT chk_pct_range CHECK (percentage >= 0 AND percentage <= 100)');
            DB::statement('ALTER TABLE commission_allocations ADD CONSTRAINT chk_alloc_amount CHECK (amount >= 0)');
        }
    }

    public function down(): void {
        Schema::dropIfExists('reward_milestone_progress');
        Schema::dropIfExists('commission_allocations');
        Schema::dropIfExists('area_dealer_assignments');
        Schema::dropIfExists('dealer_ranks');
        Schema::dropIfExists('commission_rule_parameters');
        Schema::dropIfExists('referral_tree_paths');
    }
};
