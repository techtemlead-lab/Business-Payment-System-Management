<?php

namespace App\Console\Commands;

use App\Domain\Finance\PaymentAccounts;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PrepareDemo extends Command
{
    protected $signature = 'madina:demo';

    protected $description = 'Create synthetic local demo accounts and sample catalog; never run in production.';

    public function handle(): int
    {
        if (! app()->environment('local') || ! config('madina.preview_mode')) {
            $this->error('Demo setup requires a local environment with MADINA_PREVIEW_MODE enabled.');

            return self::FAILURE;
        }
        $path = base_path('.local/demo-access.json');
        if (file_exists($path) && User::whereIn('email', ['admin@madina.test', 'dealer@madina.test', 'subdealer@madina.test'])->count() === 3) {
            $this->info('Demo already prepared. Access details are in the ignored .local directory.');

            return self::SUCCESS;
        }
        $password = file_exists($path) ? json_decode(file_get_contents($path), true)['password'] : Str::random(20).'aA1!';
        DB::transaction(function () use ($password) {
            $admin = $this->demoUser('admin@madina.test', [
                'name' => 'Company Administrator',
                'role' => 'super_admin',
                'password' => $password,
            ]);
            $dealer = $this->demoUser('dealer@madina.test', [
                'name' => 'Demo Dealer',
                'role' => 'dealer',
                'password' => $password,
                'dealer_code' => 'DEMO-D01',
                'district' => 'Dhaka',
                'division' => 'Dhaka',
                'directory_visible' => true,
                'phone' => 'Demo contact only',
                'latitude' => 23.8103,
                'longitude' => 90.4125,
            ]);
            $sub = $this->demoUser('subdealer@madina.test', [
                'name' => 'Demo Sub-dealer',
                'role' => 'sub_dealer',
                'password' => $password,
                'dealer_code' => 'DEMO-S01',
                'district' => 'Gazipur',
                'division' => 'Dhaka',
                'sponsor_id' => $dealer->id,
            ]);
            foreach ([$admin, $dealer, $sub] as $u) {
                DB::table('wallets')->updateOrInsert(['user_id' => $u->id], ['available' => '0.00', 'held' => '0.00', 'created_at' => now(), 'updated_at' => now()]);
            }
            $samples = [['DEMO-01', 'Herbal Care — Demo', 'Herbal range', '200.00', '140.00', 'sage'], ['DEMO-02', 'Daily Care — Demo', 'Everyday range', '350.00', '245.00', 'sand'], ['DEMO-03', 'Botanical Care — Demo', 'Botanical range', '500.00', '350.00', 'rose'], ['DEMO-04', 'Essential Care — Demo', 'Essential range', '800.00', '560.00', 'sky']];
            foreach ($samples as [$sku, $name, $category, $mrp, $price, $tone]) {
                $p = Product::query()->firstOrNew(['sku' => $sku]);
                if (! $p->exists) {
                    $p->fill([
                        'name' => $name,
                        'category' => $category,
                        'mrp' => $mrp,
                        'dealer_price' => $price,
                        'tone' => $tone,
                        'active' => true,
                        'description' => 'Illustrative demo product. Replace with company-approved product information.',
                    ])->save();
                }
                if ((int) $p->stock === 0) {
                    $p->update(['stock' => 120]);
                    DB::table('stock_movements')->insert(['product_id' => $p->id, 'quantity' => 120, 'kind' => 'receipt', 'reference' => 'demo-opening-'.$sku, 'reason' => 'Synthetic demo opening stock', 'actor_id' => $admin->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
            $service = app(OrderService::class);
            $p = Product::where('sku', 'DEMO-01')->first();
            if (! Order::where('user_id', $dealer->id)->exists()) {
                $o = $service->create($dealer, ['items' => [['product_id' => $p->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid(), 'reference' => 'DEMO-COLLECTION-001', 'channel' => 'Bank', 'amount' => '1000.00', 'note' => 'Synthetic demo order, not a real sale.']);
                $service->decision($admin, $o, 'approved', 'Synthetic demo credit review', true);
                $claim = DB::table('payment_claims')->where('order_id', $o->id)->value('id');
                $service->verify($admin, $claim, true, 'Synthetic demo verification');
                $line = $o->lines()->first();
                $service->dispatch($admin, $o, ['items' => [['line_id' => $line->id, 'quantity' => 4]], 'courier' => 'Demo courier', 'tracking' => 'DEMO-TRACK-001', 'idempotency_key' => (string) Str::uuid(), 'eta' => now()->addDays(3)->toDateString()]);
                $service->create($sub, ['items' => [['product_id' => $p->id, 'quantity' => 5]], 'idempotency_key' => (string) Str::uuid(), 'note' => 'Synthetic sub-dealer request.']);
            }
            if (! DB::table('payment_accounts')->exists()) {
                $accounts = app(PaymentAccounts::class);
                $accounts->save($admin, [
                    'label' => 'Demo Corporate Collection',
                    'channel' => 'Bank',
                    'holder' => 'Madina Natural Health Care',
                    'account_number' => '205014500001',
                    'details' => 'Synthetic demo collection account only.',
                    'collects_payments' => true,
                    'pays_out' => false,
                    'is_active' => true,
                    'reason' => 'Synthetic demo treasury collection account',
                ]);
                $accounts->save($admin, [
                    'label' => 'Demo Partner Payout Wallet',
                    'channel' => 'bKash',
                    'holder' => 'Madina Natural Health Care',
                    'account_number' => '01700123456',
                    'details' => 'Synthetic demo payout wallet only.',
                    'collects_payments' => false,
                    'pays_out' => true,
                    'is_active' => true,
                    'reason' => 'Synthetic demo treasury payout account',
                ]);
            }
            Audit::record($admin->id, 'demo.prepared', 'local');
        });
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, json_encode(['notice' => 'LOCAL SYNTHETIC DEMO ONLY', 'accounts' => ['admin' => 'admin@madina.test', 'dealer' => 'dealer@madina.test', 'subdealer' => 'subdealer@madina.test'], 'password' => $password], JSON_PRETTY_PRINT));
        $this->info('Synthetic demo ready. Private local access details: .local/demo-access.json');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function demoUser(string $email, array $attributes): User
    {
        $user = User::query()->where('email', $email)->first();
        if ($user) {
            return $user;
        }

        $user = (new User)->forceFill(array_merge([
            'email' => $email,
            'status' => 'active',
        ], $attributes));
        $user->save();

        return $user;
    }
}
