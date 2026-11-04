<?php

namespace App\Console\Commands;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionPosting;
use App\Domain\Commissions\CommissionQualification;
use App\Models\Order;
use App\Models\User;
use App\Support\Money;
use App\Support\VolumeHistoryPlan;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;

#[Signature('madina:volume-history {--rebuild : Delete the previous volume history and seed it again}')]
#[Description('Seed branch, partner, and invoice history above 100 billion and record commission splits without posting wallets')]
class SeedVolumeHistory extends Command
{
    /** @var list<array<string, mixed>> */
    private array $orders = [];

    /** @var list<array<string, mixed>> */
    private array $lines = [];

    /** @var list<array<string, mixed>> */
    private array $batches = [];

    /** @var list<array<string, mixed>> */
    private array $allocations = [];

    public function handle(CommissionPolicy $policies, CommissionPosting $posting, CommissionQualification $qualification): int
    {
        if (DB::getDatabaseName() !== 'madina') {
            $this->error('Refusing to seed volume history outside the madina database. Current database: '.DB::getDatabaseName());

            return self::FAILURE;
        }
        if (config('madina.financial_activation') || ! config('madina.preview_mode')) {
            $this->error('Volume history does not post wallets. Leave financial activation off and preview mode on.');

            return self::FAILURE;
        }

        $rules = $policies->snapshot(false)['rules'];
        if (! $this->rewardsFit($rules)) {
            return self::FAILURE;
        }

        if (DB::table('users')->where('dealer_code', 'like', 'VOL-%')->exists()) {
            if (! $this->option('rebuild')) {
                $this->error('Volume history is already present. Re-run with --rebuild to replace it.');

                return self::FAILURE;
            }
            $this->wipe();
        }

        DB::disableQueryLog();
        $password = DB::table('users')->where('email', 'admin@madina.test')->value('password');
        if (! is_string($password) || $password === '') {
            $this->error('The local admin account is required so volume partners can share its password hash.');

            return self::FAILURE;
        }

        $branchIds = $this->seedBranches();
        $productIds = $this->seedProducts();
        $partners = $this->seedPartners($password, $branchIds);
        $this->seedAreaAssignments($partners);
        $this->seedReferralPaths();
        $this->promoteDealers($qualification, $rules, $partners);
        $this->backdateRankAchievements();

        $templates = $this->probeTemplates($posting, $policies, $partners, $productIds[0]);
        $this->seedOrders($partners, $productIds, $templates, $policies->snapshot(false));
        $this->refreshMonthlyTotals();
        $this->refreshRewards($qualification, $partners);

        return $this->verify();
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     */
    private function rewardsFit(array $rules): bool
    {
        $carTarget = (float) $rules['car_bike_incentive']['parameters']['car_target'];
        $carCap = (float) $rules['car_bike_incentive']['parameters']['car_leg_cap'];
        $bikeTarget = (float) $rules['car_bike_incentive']['parameters']['bike_target'];
        $bikeCap = (float) $rules['car_bike_incentive']['parameters']['bike_leg_cap'];
        $legs = VolumeHistoryPlan::SM_COUNT + VolumeHistoryPlan::EXTRA_CAR_LEGS;
        if ($legs * $carCap < $carTarget || VolumeHistoryPlan::AM_PER_SM * $bikeCap < $bikeTarget) {
            $this->error('The published car or bike targets are higher than this network can qualify.');

            return false;
        }
        $lifetime = VolumeHistoryPlan::ORDERS_PER_PARTNER * VolumeHistoryPlan::INVOICE_TAKA;
        if ($lifetime < (float) $rules['rank_royalty']['parameters']['mo_target']) {
            $this->error('Partner lifetime purchases do not reach the marketing-officer target.');

            return false;
        }

        return true;
    }

    private function wipe(): void
    {
        $this->warn('Removing the previous volume history.');
        $volUsers = function ($query): void {
            $query->select('id')->from('users')->where('dealer_code', 'like', 'VOL-%');
        };
        $volOrders = function ($query) use ($volUsers): void {
            $query->select('id')->from('orders')->whereIn('user_id', $volUsers);
        };
        DB::table('commission_batches')->whereIn('order_id', $volOrders)->delete();
        DB::table('order_lines')->whereIn('order_id', $volOrders)->delete();
        DB::table('orders')->whereIn('user_id', $volUsers)->delete();
        DB::table('account_notices')->whereIn('user_id', $volUsers)->delete();
        DB::table('users')->where('dealer_code', 'like', 'VOL-%')->delete();
        DB::table('products')->where('sku', 'like', 'VOL-P-%')->delete();
        DB::table('company_branches')->where('code', 'like', 'VOL-BR-%')->delete();
    }

    /**
     * @return list<int>
     */
    private function seedBranches(): array
    {
        $now = now();
        $ids = [];
        foreach (VolumeHistoryPlan::branches() as $branch) {
            $existing = DB::table('company_branches')->where('code', $branch['code'])->value('id');
            if ($existing) {
                $ids[] = (int) $existing;

                continue;
            }
            $ids[] = (int) DB::table('company_branches')->insertGetId([
                'code' => $branch['code'],
                'name' => $branch['name'],
                'division' => $branch['division'],
                'district' => $branch['district'],
                'address' => $branch['district'].', Bangladesh',
                'phone' => '01700000000',
                'is_active' => true,
                'is_delivery_point' => true,
                'is_stock_hub' => true,
                'notes' => 'Volume history branch',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $this->info('Branches ready: '.count($ids));

        return $ids;
    }

    /**
     * @return list<int>
     */
    private function seedProducts(): array
    {
        $now = now();
        $rows = [];
        for ($index = 1; $index <= VolumeHistoryPlan::PRODUCTS; $index++) {
            $rows[] = [
                'sku' => sprintf('VOL-P-%03d', $index),
                'name' => sprintf('Madina Formula %02d', $index),
                'category' => 'Natural Care',
                'sub_category' => 'General',
                'description' => 'Volume history catalog item',
                'unit' => 'pack',
                'strength' => '10 x 10',
                'mrp' => '7000.00',
                'dealer_price' => '7000.00',
                'sub_dealer_price' => '7000.00',
                'cost_price' => '4200.00',
                'stock' => 5_000_000,
                'low_stock_at' => 10,
                'active' => true,
                'is_stock_paused' => false,
                'featured' => false,
                'tone' => 'sage',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 70) as $chunk) {
            DB::table('products')->insert($chunk);
        }
        $ids = DB::table('products')->where('sku', 'like', 'VOL-P-%')->orderBy('sku')->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $this->info('Products ready: '.count($ids));

        return $ids;
    }

    /**
     * @param  list<int>  $branchIds
     * @return list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>
     */
    private function seedPartners(string $password, array $branchIds): array
    {
        $tree = VolumeHistoryPlan::dealerTree();
        $branchOf = VolumeHistoryPlan::branchIndexByDealer();
        $branches = VolumeHistoryPlan::branches();
        $created = '2026-03-01 02:00:00';
        $rows = [];
        foreach ($tree as $slot => $node) {
            $branch = $branchOf[$slot];
            $place = $branches[$branch];
            $code = sprintf('VOL-D-%05d', $slot + 1);
            $rows[] = $this->partnerRow($code, 'Dealer '.($slot + 1).' · '.$place['district'], 'dealer', $password, $place, $branchIds[$branch], $created, $slot);
        }
        foreach (VolumeHistoryPlan::subDealerSponsors() as $index => $sponsorSlot) {
            $branch = $branchOf[$sponsorSlot];
            $place = $branches[$branch];
            $code = sprintf('VOL-S-%05d', $index + 1);
            $rows[] = $this->partnerRow($code, 'Sub-dealer '.($index + 1).' · '.$place['district'], 'sub_dealer', $password, $place, $branchIds[$branch], $created, null);
        }
        foreach (array_chunk($rows, 400) as $chunk) {
            DB::table('users')->insert($chunk);
        }

        $ids = DB::table('users')->where('dealer_code', 'like', 'VOL-%')->pluck('id', 'dealer_code');
        $dealerIds = [];
        foreach ($tree as $slot => $node) {
            $dealerIds[$slot] = (int) $ids[sprintf('VOL-D-%05d', $slot + 1)];
        }
        $sponsorUpdates = [];
        foreach ($tree as $slot => $node) {
            if ($node['parent'] !== null) {
                $sponsorUpdates[$dealerIds[$slot]] = $dealerIds[$node['parent']];
            }
        }
        $partners = [];
        foreach ($tree as $slot => $node) {
            $uplines = [];
            $parent = $node['parent'];
            while ($parent !== null && count($uplines) < 3) {
                $uplines[] = $dealerIds[$parent];
                $parent = $tree[$parent]['parent'];
            }
            $partners[] = [
                'id' => $dealerIds[$slot],
                'slot' => $slot,
                'role' => 'dealer',
                'branch' => $branchOf[$slot],
                'uplines' => $uplines,
                'code' => sprintf('VOL-D-%05d', $slot + 1),
            ];
        }
        foreach (VolumeHistoryPlan::subDealerSponsors() as $index => $sponsorSlot) {
            $uplines = [$dealerIds[$sponsorSlot]];
            $parent = $tree[$sponsorSlot]['parent'];
            while ($parent !== null && count($uplines) < 3) {
                $uplines[] = $dealerIds[$parent];
                $parent = $tree[$parent]['parent'];
            }
            $code = sprintf('VOL-S-%05d', $index + 1);
            $userId = (int) $ids[$code];
            $sponsorUpdates[$userId] = $dealerIds[$sponsorSlot];
            $partners[] = [
                'id' => $userId,
                'slot' => null,
                'role' => 'sub_dealer',
                'branch' => $branchOf[$sponsorSlot],
                'uplines' => $uplines,
                'code' => $code,
            ];
        }
        foreach ($sponsorUpdates as $userId => $sponsorId) {
            DB::table('users')->where('id', $userId)->update(['sponsor_id' => $sponsorId]);
        }
        $this->info('Partners ready: '.count($partners));

        return $partners;
    }

    /**
     * @param  array{code: string, name: string, division: string, district: string, existing: bool}  $place
     * @return array<string, mixed>
     */
    private function partnerRow(string $code, string $name, string $role, string $password, array $place, int $branchId, string $created, ?int $slot): array
    {
        $phoneSeed = $role === 'dealer' ? 2_000_000 : 4_000_000;
        $serial = $slot ?? (int) substr($code, -5);

        return [
            'name' => $name,
            'email' => strtolower($code).'@madina.test',
            'email_verified_at' => $created,
            'password' => $password,
            'role' => $role,
            'status' => 'active',
            'dealer_code' => $code,
            'refer_code' => $code,
            'phone' => '018'.str_pad((string) ($phoneSeed + $serial), 8, '0', STR_PAD_LEFT),
            'directory_visible' => true,
            'division' => $place['division'],
            'district' => $place['district'],
            'active_branch_id' => $branchId,
            'locale' => 'en',
            'created_at' => $created,
            'updated_at' => $created,
        ];
    }

    /**
     * @param  list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>  $partners
     */
    private function seedAreaAssignments(array $partners): void
    {
        $now = now();
        $assignees = [];
        foreach ($partners as $partner) {
            if ($partner['role'] !== 'dealer') {
                continue;
            }
            $branch = $partner['branch'];
            if (! isset($assignees[$branch]) || $partner['slot'] < $assignees[$branch]['slot']) {
                $assignees[$branch] = $partner;
            }
        }
        $rows = [];
        foreach (VolumeHistoryPlan::branches() as $index => $branch) {
            $userId = $assignees[$index]['id'];
            foreach (['division' => $branch['division'], 'district' => $branch['district']] as $level => $zone) {
                $rows[] = [
                    'user_id' => $userId,
                    'level' => $level,
                    'zone_name' => $zone,
                    'division' => $branch['division'],
                    'district' => $level === 'district' ? $branch['district'] : null,
                    'monthly_quota' => '0.00',
                    'current_month_purchase' => '0.00',
                    'is_active' => true,
                    'territory_key' => 'VOL-'.strtoupper(substr($level, 0, 3)).'-'.$branch['code'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }
        DB::table('area_dealer_assignments')->insert($rows);
    }

    private function seedReferralPaths(): void
    {
        $now = now();
        DB::statement(<<<'SQL'
            WITH RECURSIVE paths AS (
                SELECT id AS ancestor_id, id AS descendant_id, 0 AS depth, ARRAY[id]::bigint[] AS trail
                FROM users
                WHERE dealer_code LIKE 'VOL-%'
                UNION ALL
                SELECT paths.ancestor_id, users.id, paths.depth + 1, paths.trail || users.id
                FROM paths
                INNER JOIN users ON users.sponsor_id = paths.descendant_id
                WHERE users.dealer_code LIKE 'VOL-%'
                  AND NOT users.id = ANY (paths.trail)
            )
            INSERT INTO referral_tree_paths (ancestor_id, descendant_id, depth, created_at, updated_at)
            SELECT ancestor_id, descendant_id, depth, ?, ?
            FROM paths
            ON CONFLICT (ancestor_id, descendant_id) DO NOTHING
        SQL, [$now, $now]);
    }

    /**
     * @param  array<string, array<string, mixed>>  $rules
     * @param  list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>  $partners
     */
    private function promoteDealers(CommissionQualification $qualification, array $rules, array $partners): void
    {
        $tree = VolumeHistoryPlan::dealerTree();
        $models = User::query()->where('dealer_code', 'like', 'VOL-D-%')->get()->keyBy('dealer_code');
        $bySlot = [];
        foreach ($partners as $partner) {
            if ($partner['slot'] !== null) {
                $bySlot[$partner['slot']] = $models[$partner['code']];
            }
        }
        $cash = Money::cents((string) (VolumeHistoryPlan::ORDERS_PER_PARTNER * VolumeHistoryPlan::INVOICE_TAKA).'.00');
        $ordered = array_keys($bySlot);
        usort($ordered, function (int $left, int $right) use ($tree): int {
            return $this->depth($tree, $right) <=> $this->depth($tree, $left);
        });
        foreach ($ordered as $slot) {
            $qualification->record($bySlot[$slot], $cash, $rules);
        }
        foreach ($this->leafBySalesManager($tree) as $slot) {
            $qualification->record($bySlot[$slot], 0, $rules);
        }
        $qualification->record($bySlot[VolumeHistoryPlan::leafDealerSlots()[0]], 0, $rules);

        $now = now();
        $subRanks = [];
        foreach ($partners as $partner) {
            if ($partner['role'] !== 'sub_dealer') {
                continue;
            }
            $subRanks[] = [
                'user_id' => $partner['id'],
                'current_rank' => 'dealer',
                'achieved_at' => $now,
                'lifetime_purchase_total' => '70000000.00',
                'monthly_purchase_total' => '0.00',
                'purchase_period' => '2026-11',
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }
        foreach (array_chunk($subRanks, 400) as $chunk) {
            DB::table('dealer_ranks')->insert($chunk);
        }
        $this->info('Dealer ranks recorded.');
    }

    /**
     * @param  list<array{parent: int|null, rank: string}>  $tree
     */
    private function depth(array $tree, int $slot): int
    {
        $depth = 0;
        $parent = $tree[$slot]['parent'];
        while ($parent !== null) {
            $depth++;
            $parent = $tree[$parent]['parent'];
        }

        return $depth;
    }

    /**
     * @param  list<array{parent: int|null, rank: string}>  $tree
     * @return array<int, int>
     */
    private function leafBySalesManager(array $tree): array
    {
        $leaves = [];
        foreach (VolumeHistoryPlan::leafDealerSlots() as $slot) {
            $areaManager = $tree[$slot]['parent'];
            $salesManager = $tree[$areaManager]['parent'];
            $leaves[$salesManager] = $slot;
        }

        return $leaves;
    }

    /**
     * @param  list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>  $partners
     * @return array<string, array{account_type: string, company: int, allocated: int, unallocated: int, rows: list<array<string, mixed>>}>
     */
    private function probeTemplates(CommissionPosting $posting, CommissionPolicy $policies, array $partners, int $productId): array
    {
        $wanted = ['d0' => null, 'd1' => null, 'd2' => null, 'd3' => null, 'sub' => null];
        foreach ($partners as $partner) {
            $key = $partner['role'] === 'sub_dealer' ? 'sub' : 'd'.count($partner['uplines']);
            if (array_key_exists($key, $wanted) && $wanted[$key] === null) {
                $wanted[$key] = $partner;
            }
        }
        $templates = [];
        foreach ($wanted as $key => $partner) {
            if ($partner === null) {
                throw new \RuntimeException('Missing a partner for commission pattern '.$key);
            }
            $templates[$key] = $this->probe($posting, $policies, $partner, $productId);
            $cash = Money::cents((string) VolumeHistoryPlan::INVOICE_TAKA.'.00');
            $split = $templates[$key]['company'] + $templates[$key]['allocated'] + $templates[$key]['unallocated'];
            if ($split !== $cash) {
                throw new \RuntimeException('Commission split for '.$key.' does not fund the invoice.');
            }
        }
        $this->info('Commission patterns calculated from the live policy.');

        return $templates;
    }

    /**
     * @param  array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}  $partner
     * @return array{account_type: string, company: int, allocated: int, unallocated: int, rows: list<array<string, mixed>>}
     */
    private function probe(CommissionPosting $posting, CommissionPolicy $policies, array $partner, int $productId): array
    {
        $orderId = (string) Str::uuid();
        $now = now();
        DB::table('orders')->insert([
            'id' => $orderId,
            'number' => 'VOL-PROBE-'.$partner['code'],
            'user_id' => $partner['id'],
            'status' => 'delivered',
            'total' => '700000.00',
            'paid' => '700000.00',
            'due_credit' => '0.00',
            'is_opening_balance' => false,
            'idempotency_key' => 'probe-'.$partner['code'],
            'payload_hash' => hash('sha256', 'probe-'.$partner['code']),
            'fulfillment_branch_id' => DB::table('users')->where('id', $partner['id'])->value('active_branch_id'),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('order_lines')->insert([
            'order_id' => $orderId,
            'product_id' => $productId,
            'name' => 'Madina Formula 01',
            'sku' => 'VOL-P-001',
            'quantity' => 100,
            'dispatched' => 100,
            'mrp' => '7000.00',
            'unit_price' => '7000.00',
            'total' => '700000.00',
            'category_snapshot' => 'Natural Care',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        try {
            $buyer = User::query()->findOrFail($partner['id']);
            $order = Order::query()->with('lines')->findOrFail($orderId);
            $posting->capture($order, $buyer, 'approval');
            $method = new ReflectionMethod(CommissionPosting::class, 'calculate');
            /** @var array{rows: list<array<string, mixed>>, company: int, allocated: int, cash: int} $result */
            $result = $method->invoke($posting, $order->fresh('lines'), $buyer, $order->fresh()->commission_policy_snapshot);
        } finally {
            DB::table('order_lines')->where('order_id', $orderId)->delete();
            DB::table('orders')->where('id', $orderId)->delete();
        }
        $rows = [];
        foreach ($result['rows'] as $row) {
            $rows[] = [
                'category' => $row['category'],
                'rate_percentage' => $row['rate_percentage'],
                'cents' => $row['cents'],
                'status' => $row['status'],
            ];
        }

        return [
            'account_type' => $partner['role'] === 'sub_dealer' ? 'sub_dealer' : 'dealer',
            'company' => $result['company'],
            'allocated' => $result['allocated'],
            'unallocated' => $result['cash'] - $result['company'] - $result['allocated'],
            'rows' => $rows,
            'policy' => $policies->snapshot(false),
        ];
    }

    /**
     * @param  list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>  $partners
     * @param  list<int>  $productIds
     * @param  array<string, array{account_type: string, company: int, allocated: int, unallocated: int, rows: list<array<string, mixed>>, policy: array<string, mixed>}>  $templates
     * @param  array<string, mixed>  $snapshot
     */
    private function seedOrders(array $partners, array $productIds, array $templates, array $snapshot): void
    {
        $assignees = [];
        foreach ($partners as $partner) {
            if ($partner['role'] === 'dealer' && (! isset($assignees[$partner['branch']]) || $partner['slot'] < $assignees[$partner['branch']]['slot'])) {
                $assignees[$partner['branch']] = $partner;
            }
        }
        $branchIds = DB::table('users')->where('dealer_code', 'like', 'VOL-%')->pluck('active_branch_id', 'id')->map(fn ($id): int => (int) $id)->all();
        $start = Carbon::parse(VolumeHistoryPlan::PERIOD_START.' 09:00:00', 'Asia/Dhaka');
        $end = Carbon::parse(VolumeHistoryPlan::PERIOD_END.' 18:00:00', 'Asia/Dhaka');
        $span = (int) $start->diffInSeconds($end);
        $number = 0;
        $policyByRole = [];
        foreach (['dealer', 'sub_dealer'] as $role) {
            $key = $role === 'sub_dealer' ? 'sub' : 'd3';
            $policyByRole[$role] = json_encode([
                'account_type' => $templates[$key]['account_type'],
                'basis' => $snapshot['basis'],
                'company_mrp_percent' => $snapshot['company_mrp_percent'],
                'rules' => $snapshot['rules'],
                'volume_history' => true,
            ], JSON_THROW_ON_ERROR);
        }
        $orderSnapshot = [];
        foreach ($templates as $key => $template) {
            $orderSnapshot[$key] = json_encode([
                'capture_event' => 'approval',
                'account_type' => $template['account_type'],
                'volume_history' => true,
                'approved_company_share' => Money::decimal($template['company']),
            ], JSON_THROW_ON_ERROR);
        }

        foreach ($partners as $offset => $partner) {
            $key = $partner['role'] === 'sub_dealer' ? 'sub' : 'd'.count($partner['uplines']);
            $template = $templates[$key];
            $branchUser = $assignees[$partner['branch']]['id'];
            for ($nth = 0; $nth < VolumeHistoryPlan::ORDERS_PER_PARTNER; $nth++) {
                $number++;
                $at = $start->copy()->addSeconds((int) floor($span * $nth / 99))->addSeconds($offset % 3600);
                $utc = $at->utc()->format('Y-m-d H:i:s');
                $orderId = (string) Str::uuid();
                $productSlot = ($offset + $nth) % count($productIds);
                $this->orders[] = [
                    'id' => $orderId,
                    'number' => sprintf('VOL-%07d', $number),
                    'user_id' => $partner['id'],
                    'status' => 'delivered',
                    'fulfillment_branch_id' => $branchIds[$partner['id']],
                    'total' => '700000.00',
                    'paid' => '700000.00',
                    'due_credit' => '0.00',
                    'is_opening_balance' => false,
                    'idempotency_key' => $partner['code'].'-'.$nth,
                    'payload_hash' => hash('sha256', $partner['code'].'-'.$nth),
                    'note' => 'Volume history invoice',
                    'commission_policy_snapshot' => $orderSnapshot[$key],
                    'created_at' => $utc,
                    'updated_at' => $utc,
                ];
                $this->lines[] = [
                    'order_id' => $orderId,
                    'product_id' => $productIds[$productSlot],
                    'name' => sprintf('Madina Formula %02d', $productSlot + 1),
                    'sku' => sprintf('VOL-P-%03d', $productSlot + 1),
                    'quantity' => 100,
                    'dispatched' => 100,
                    'mrp' => '7000.00',
                    'unit_price' => '7000.00',
                    'total' => '700000.00',
                    'category_snapshot' => 'Natural Care',
                    'created_at' => $utc,
                    'updated_at' => $utc,
                ];
                $this->batches[] = [
                    'order_id' => $orderId,
                    'account_type' => $template['account_type'],
                    'period_month' => $at->format('Y-m'),
                    'cash_basis' => '700000.00',
                    'mrp_basis' => '700000.00',
                    'company_share' => Money::decimal($template['company']),
                    'total_allocated' => Money::decimal($template['allocated']),
                    'unallocated' => Money::decimal($template['unallocated']),
                    'refunded_amount' => '0.00',
                    'policy' => $policyByRole[$partner['role']],
                    'created_at' => $utc,
                    'updated_at' => $utc,
                ];
                foreach ($template['rows'] as $row) {
                    $this->allocations[] = $this->allocation($orderId, $partner, $branchUser, $row, $utc, $at->format('Y-m'));
                }
                if ($template['unallocated'] > 0) {
                    $rateBps = intdiv(($template['unallocated'] * 10000) + intdiv(Money::cents('700000.00'), 2), Money::cents('700000.00'));
                    $this->allocations[] = [
                        'order_id' => $orderId,
                        'user_id' => null,
                        'category' => 'company_commission_benefits_fund',
                        'rate_percentage' => number_format($rateBps / 100, 2, '.', ''),
                        'cash_basis' => '700000.00',
                        'amount' => Money::decimal($template['unallocated']),
                        'original_amount' => Money::decimal($template['unallocated']),
                        'status' => 'reserved',
                        'metadata' => json_encode([
                            'basis' => 'purchase_value',
                            'period_month' => $at->format('Y-m'),
                            'retained_by' => 'owner_commission_benefits_fund',
                            'volume_history' => true,
                        ], JSON_THROW_ON_ERROR),
                        'created_at' => $utc,
                        'updated_at' => $utc,
                    ];
                }
                if (count($this->orders) >= 100) {
                    $this->flushOrders();
                    $this->line('Invoices written: '.number_format($number));
                }
            }
        }
        $this->flushOrders();
        $this->info('Invoices written: '.number_format($number));
    }

    /**
     * @param  array{id: int, uplines: list<int>}  $partner
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function allocation(string $orderId, array $partner, int $areaUserId, array $row, string $utc, string $month): array
    {
        $userId = match ($row['category']) {
            'own_dealer_cashback', 'sub_dealer_salary', 'sub_dealer_tour' => $partner['id'],
            'direct_referral' => $partner['uplines'][0] ?? null,
            'd2d_gen2' => $partner['uplines'][1] ?? null,
            'd2d_gen3' => $partner['uplines'][2] ?? null,
            'area_division', 'area_district' => $areaUserId,
            default => null,
        };

        return [
            'order_id' => $orderId,
            'user_id' => $userId,
            'category' => $row['category'],
            'rate_percentage' => $row['rate_percentage'],
            'cash_basis' => '700000.00',
            'amount' => Money::decimal((int) $row['cents']),
            'original_amount' => Money::decimal((int) $row['cents']),
            'status' => $row['status'],
            'metadata' => json_encode([
                'basis' => 'purchase_value',
                'period_month' => $month,
                'volume_history' => true,
            ], JSON_THROW_ON_ERROR),
            'created_at' => $utc,
            'updated_at' => $utc,
        ];
    }

    private function flushOrders(): void
    {
        if ($this->orders === []) {
            return;
        }
        DB::transaction(function (): void {
            foreach (array_chunk($this->orders, 100) as $chunk) {
                DB::table('orders')->insert($chunk);
            }
            foreach (array_chunk($this->lines, 200) as $chunk) {
                DB::table('order_lines')->insert($chunk);
            }
            foreach (array_chunk($this->batches, 50) as $chunk) {
                DB::table('commission_batches')->insert($chunk);
            }
            foreach (array_chunk($this->allocations, 200) as $chunk) {
                DB::table('commission_allocations')->insert($chunk);
            }
        });
        $this->orders = [];
        $this->lines = [];
        $this->batches = [];
        $this->allocations = [];
    }

    private function refreshMonthlyTotals(): void
    {
        DB::update(<<<'SQL'
            UPDATE dealer_ranks AS ranks
            SET monthly_purchase_total = volumes.volume,
                purchase_period = ?,
                monthly_qualified = volumes.volume >= 10000,
                updated_at = ?
            FROM (
                SELECT orders.user_id, COALESCE(SUM(commission_batches.cash_basis - commission_batches.refunded_amount), 0) AS volume
                FROM commission_batches
                INNER JOIN orders ON orders.id = commission_batches.order_id
                INNER JOIN users ON users.id = orders.user_id
                WHERE users.dealer_code LIKE 'VOL-%'
                  AND commission_batches.period_month = ?
                GROUP BY orders.user_id
            ) AS volumes
            WHERE ranks.user_id = volumes.user_id
        SQL, ['2026-11', now(), '2026-11']);
    }

    /**
     * @param  list<array{id: int, slot: int|null, role: string, branch: int, uplines: list<int>, code: string}>  $partners
     */
    private function refreshRewards(CommissionQualification $qualification, array $partners): void
    {
        $tree = VolumeHistoryPlan::dealerTree();
        $slots = [0];
        foreach ($this->leafBySalesManager($tree) as $salesManager => $leaf) {
            $slots[] = $salesManager;
            $slots[] = $tree[$leaf]['parent'];
        }
        foreach (array_unique($slots) as $slot) {
            foreach ($partners as $partner) {
                if ($partner['slot'] === $slot) {
                    $qualification->refreshMilestones($partner['id']);
                    break;
                }
            }
        }
        $this->info('Car and bike progress refreshed for the leadership legs.');
        $this->backdateRewardAchievements();
    }

    private function backdateRankAchievements(): void
    {
        $achievedAt = VolumeHistoryPlan::PERIOD_START.' 03:00:00';
        DB::update(<<<'SQL'
            UPDATE dealer_ranks AS ranks
            SET qualification_history = rewritten.history
            FROM (
                SELECT ranks.user_id,
                       jsonb_agg(
                           jsonb_set(entry.value, '{achieved_at}', '"2026-03-03T03:00:00+00:00"'::jsonb)
                           ORDER BY entry.ordinality
                       ) AS history
                FROM dealer_ranks AS ranks
                INNER JOIN users ON users.id = ranks.user_id
                CROSS JOIN LATERAL jsonb_array_elements(ranks.qualification_history::jsonb) WITH ORDINALITY AS entry(value, ordinality)
                WHERE users.dealer_code LIKE 'VOL-D-%'
                  AND ranks.qualification_history IS NOT NULL
                GROUP BY ranks.user_id
            ) AS rewritten
            WHERE ranks.user_id = rewritten.user_id
        SQL);
        DB::table('dealer_ranks')
            ->whereIn('user_id', function ($query): void {
                $query->select('id')->from('users')->where('dealer_code', 'like', 'VOL-D-%');
            })
            ->update(['achieved_at' => $achievedAt]);
    }

    private function backdateRewardAchievements(): void
    {
        DB::table('reward_milestone_progress')
            ->where('achieved', true)
            ->whereIn('user_id', function ($query): void {
                $query->select('id')->from('users')->where('dealer_code', 'like', 'VOL-%');
            })
            ->update(['achieved_at' => VolumeHistoryPlan::PERIOD_START.' 03:00:00']);
    }

    private function verify(): int
    {
        $invoice = (string) DB::table('orders')->where('number', 'like', 'VOL-%')->sum('total');
        $orders = (int) DB::table('orders')->where('number', 'like', 'VOL-%')->count();
        $partners = (int) DB::table('users')->where('dealer_code', 'like', 'VOL-%')->count();
        $products = (int) DB::table('order_lines')
            ->whereIn('order_id', DB::table('orders')->select('id')->where('number', 'like', 'VOL-%'))
            ->distinct()
            ->count('product_id');
        $ranks = DB::table('dealer_ranks')
            ->join('users', 'users.id', '=', 'dealer_ranks.user_id')
            ->where('users.dealer_code', 'like', 'VOL-D-%')
            ->selectRaw('dealer_ranks.current_rank, COUNT(*) AS aggregate_count')
            ->groupBy('dealer_ranks.current_rank')
            ->pluck('aggregate_count', 'current_rank');
        $car = DB::table('reward_milestone_progress')
            ->join('users', 'users.id', '=', 'reward_milestone_progress.user_id')
            ->where('users.dealer_code', 'VOL-D-00001')
            ->where('milestone_type', 'car')
            ->value('achieved');
        $pools = DB::table('commission_allocations')
            ->whereIn('category', ['dealer_monthly_salary_pool', 'rank_royalty_pool', 'sub_dealer_salary', 'own_dealer_cashback', 'car_bike_incentive_fund'])
            ->whereIn('order_id', DB::table('orders')->select('id')->where('number', 'like', 'VOL-%'))
            ->selectRaw('category, COUNT(*) AS aggregate_count, SUM(amount) AS amount')
            ->groupBy('category')
            ->get();

        $this->line('Orders: '.number_format($orders));
        $this->line('Partners: '.number_format($partners));
        $this->line('Invoice total: '.$invoice);
        $this->line('Products used: '.$products);
        $this->line('Dealer ranks: '.json_encode($ranks));
        $this->line('Head-office leader car reward achieved: '.($car ? 'yes' : 'no'));
        foreach ($pools as $pool) {
            $this->line($pool->category.': '.$pool->amount.' across '.$pool->aggregate_count);
        }

        $ok = $orders === VolumeHistoryPlan::orderCount()
            && $partners === VolumeHistoryPlan::partnerCount()
            && (float) $invoice > 100_000_000_000
            && $products >= 70
            && (int) ($ranks['me'] ?? 0) === 1
            && (int) ($ranks['sm'] ?? 0) === 10
            && (int) ($ranks['am'] ?? 0) === 100
            && (bool) $car;

        if (! $ok) {
            $this->error('Volume history did not meet the branch, invoice, rank, or reward checks.');

            return self::FAILURE;
        }
        $this->info('Volume history is in place. Wallet balances were not credited because financial posting is off.');

        return self::SUCCESS;
    }
}
