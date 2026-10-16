<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->decimal('sub_dealer_price', 14, 2)->nullable();
            $table->boolean('is_stock_paused')->default(false);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->json('commission_policy_snapshot')->nullable();
        });
        Schema::table('dealer_ranks', function (Blueprint $table): void {
            $table->string('purchase_period', 7)->nullable();
        });
        Schema::table('wallets', function (Blueprint $table): void {
            $table->decimal('commission_held', 14, 2)->default(0);
        });
        Schema::table('withdrawals', function (Blueprint $table): void {
            $table->decimal('withheld_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->nullable();
        });
        Schema::table('commission_allocations', function (Blueprint $table): void {
            $table->decimal('original_amount', 14, 2)->nullable();
            $table->index(['order_id', 'category']);
        });
        Schema::table('area_dealer_assignments', function (Blueprint $table): void {
            $table->string('territory_key', 64)->nullable()->unique();
        });
        Schema::create('commission_policy_revisions', function (Blueprint $table): void {
            $table->id();
            $table->json('rules');
            $table->string('source', 32)->default('admin');
            $table->timestamp('effective_at');
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->timestamps();
        });
        Schema::create('commission_batches', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('order_id')->unique()->constrained('orders');
            $table->string('account_type', 32);
            $table->string('period_month', 7)->index();
            foreach (['cash_basis', 'mrp_basis', 'company_share', 'total_allocated', 'unallocated', 'refunded_amount'] as $column) {
                $table->decimal($column, 14, 2)->default(0);
            }
            $table->json('policy');
            $table->timestamps();
        });
        Schema::create('commission_settlement_payouts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('allocation_id')->constrained('commission_allocations');
            $table->foreignId('user_id')->constrained('users');
            $table->string('kind', 32);
            $table->string('period_month', 7)->index();
            $table->decimal('amount', 14, 2);
            $table->decimal('reversed_amount', 14, 2)->default(0);
            $table->timestamps();
            $table->unique(['allocation_id', 'user_id', 'kind'], 'commission_payout_source_unique');
        });
        Schema::create('rank_incentive_awards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users');
            $table->string('rank', 32);
            $table->decimal('amount', 14, 2);
            $table->json('sources');
            $table->timestamps();
            $table->unique(['user_id', 'rank']);
        });
        Schema::create('commission_refunds', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained('orders');
            $table->string('reference', 100);
            $table->decimal('amount', 14, 2);
            $table->text('reason');
            $table->timestamps();
            $table->unique(['order_id', 'reference']);
        });
        Schema::create('commission_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('refund_id')->constrained('commission_refunds');
            $table->foreignId('allocation_id')->constrained('commission_allocations');
            $table->foreignId('user_id')->nullable()->constrained('users');
            $table->decimal('amount', 14, 2);
            $table->decimal('recovered', 14, 2)->default(0);
            $table->decimal('unrecovered', 14, 2)->default(0);
            $table->string('source_status', 32);
            $table->timestamps();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallets ADD CONSTRAINT commission_hold_valid CHECK (commission_held >= 0 AND commission_held <= held)');
            DB::statement('ALTER TABLE commission_batches ADD CONSTRAINT commission_batch_funded CHECK (cash_basis > 0 AND company_share + total_allocated + unallocated = cash_basis AND refunded_amount >= 0 AND refunded_amount <= cash_basis)');
            DB::statement('ALTER TABLE commission_settlement_payouts ADD CONSTRAINT settlement_amount_valid CHECK (amount >= 0 AND reversed_amount >= 0 AND reversed_amount <= amount)');
            DB::statement('ALTER TABLE withdrawals ADD CONSTRAINT withdrawal_net_valid CHECK (withheld_amount >= 0 AND withheld_amount <= amount AND (net_amount IS NULL OR net_amount + withheld_amount = amount))');
            DB::statement("UPDATE wallets w SET commission_held = LEAST(w.held, c.amount) FROM (SELECT user_id, SUM(amount) AS amount FROM commission_allocations WHERE status = 'maturing' GROUP BY user_id) c WHERE w.user_id = c.user_id");
            DB::statement('WITH RECURSIVE paths AS (
                SELECT id AS ancestor_id, id AS descendant_id, 0 AS depth, ARRAY[id] AS trail FROM users
                UNION ALL SELECT p.ancestor_id, u.id, p.depth + 1, p.trail || u.id FROM paths p JOIN users u ON u.sponsor_id = p.descendant_id WHERE NOT u.id = ANY(p.trail)
            ) INSERT INTO referral_tree_paths (ancestor_id, descendant_id, depth, created_at, updated_at)
                SELECT ancestor_id, descendant_id, depth, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP FROM paths ON CONFLICT DO NOTHING');
        }
        $legacyRules = DB::table('commission_rule_parameters')->get();
        if ($legacyRules->isNotEmpty()) {
            $backup = [];
            foreach ($legacyRules as $rule) {
                $data = (array) $rule;
                $data['parameters'] = $rule->parameters ? json_decode($rule->parameters, true, 512, JSON_THROW_ON_ERROR) : [];
                $backup[$rule->rule_key] = $data;
            }
            DB::table('commission_policy_revisions')->insert(['rules' => json_encode($backup, JSON_THROW_ON_ERROR), 'source' => 'schema-upgrade-backup',
                'effective_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }
        $defaults = [
            'additional_rank' => ['Sub-dealer additional rank reserve', '4.00'],
            'sub_dealer_salary' => ['Sub-dealer monthly salary', '3.00'],
            'sub_dealer_tour' => ['Sub-dealer tour reserve', '3.00'],
        ];
        foreach ($defaults as $key => [$name, $percentage]) {
            DB::table('commission_rule_parameters')->insertOrIgnore(['rule_key' => $key, 'category' => 'sub_dealer', 'name' => $name,
                'percentage' => $percentage, 'parameters' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        }
        $parameters = [
            'rank_royalty' => ['mo_target' => '25000.00', 'am_count' => 10, 'sm_count' => 10, 'me_count' => 10, 'qualification_depth' => 3],
            'rank_incentive' => ['mo_bonus' => '5000.00', 'am_bonus' => '50000.00', 'sm_bonus' => '250000.00', 'me_bonus' => '300000.00'],
            'car_bike_incentive' => ['bike_target' => '5000000.00', 'bike_leg_cap' => '800000.00', 'car_target' => '250000000.00', 'car_leg_cap' => '20000000.00'],
        ];
        foreach ($parameters as $key => $values) {
            DB::table('commission_rule_parameters')->where('rule_key', $key)->where('version', 1)->whereNull('updated_by')
                ->update(['parameters' => json_encode($values, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        }
        DB::table('commission_rule_parameters')->where('rule_key', 'payout_tax_withholding')->where('version', 1)->whereNull('updated_by')
            ->update(['percentage' => '0.00', 'parameters' => '{"threshold":"0.00"}', 'updated_at' => now()]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('commission_reversals');
        Schema::dropIfExists('commission_refunds');
        Schema::dropIfExists('rank_incentive_awards');
        Schema::dropIfExists('commission_settlement_payouts');
        Schema::dropIfExists('commission_batches');
        Schema::dropIfExists('commission_policy_revisions');
        Schema::table('area_dealer_assignments', fn (Blueprint $table) => $table->dropColumn('territory_key'));
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE wallets DROP CONSTRAINT commission_hold_valid');
            DB::statement('ALTER TABLE withdrawals DROP CONSTRAINT withdrawal_net_valid');
        }
        Schema::table('commission_allocations', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'category']);
            $table->dropColumn('original_amount');
        });
        Schema::table('wallets', fn (Blueprint $table) => $table->dropColumn('commission_held'));
        Schema::table('withdrawals', fn (Blueprint $table) => $table->dropColumn(['withheld_amount', 'net_amount']));
        Schema::table('dealer_ranks', fn (Blueprint $table) => $table->dropColumn('purchase_period'));
        Schema::table('orders', fn (Blueprint $table) => $table->dropColumn('commission_policy_snapshot'));
        Schema::table('products', fn (Blueprint $table) => $table->dropColumn(['sub_dealer_price', 'is_stock_paused']));
    }
};
