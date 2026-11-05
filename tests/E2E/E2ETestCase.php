<?php

namespace Tests\E2E;

use App\Domain\Auth\TotpAuthenticator;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class E2ETestCase extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        Config::set('madina.login_otp.enabled', false);
    }

    /**
     * Provision an active wholesale dealer.
     */
    protected function createDealer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'SYN-D-'.Str::upper(Str::random(8)),
            'password' => 'DealerPassword123',
            'phone' => '017'.fake()->unique()->numerify('########'),
        ], $attributes));
    }

    /**
     * Provision an active sub-dealer.
     */
    protected function createSubDealer(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => 'sub_dealer',
            'status' => 'active',
            'dealer_code' => 'SYN-S-'.Str::upper(Str::random(8)),
            'password' => 'SubDealerPassword123',
            'phone' => '018'.fake()->unique()->numerify('########'),
        ], $attributes));
    }

    /**
     * Provision an active staff user with specified role.
     */
    protected function createStaff(string $role = 'super_admin', array $attributes = []): User
    {
        return User::factory()->staff($role)->create(array_merge([
            'status' => 'active',
            'password' => 'StaffPassword123',
            'phone' => '019'.fake()->unique()->numerify('########'),
        ], $attributes));
    }

    /**
     * Create a standard catalog product with valid pricing bounds (20-50% wholesale discount).
     */
    protected function createProduct(array $attributes = []): Product
    {
        return Product::factory()->create(array_merge([
            'name' => 'E2E Test Product '.Str::random(5),
            'mrp' => '200.00',
            'dealer_price' => '140.00', // 30% discount (valid 20-50%)
            'sub_dealer_price' => '160.00', // 20% discount (valid 20-50%)
            'stock' => 100,
            'active' => true,
        ], $attributes));
    }

    /**
     * Create a standard company branch for fulfillment and inventory.
     */
    protected function createBranch(array $attributes = []): CompanyBranch
    {
        return CompanyBranch::factory()->create(array_merge([
            'name' => 'E2E Branch '.Str::random(5),
            'code' => 'BR-'.Str::upper(Str::random(4)),
            'is_active' => true,
            'is_stock_hub' => true,
        ], $attributes));
    }

    /**
     * Seed baseline commission rules.
     */
    protected function seedCommissionRules(): void
    {
        $this->seed(CommissionRuleSeeder::class);
    }

    /**
     * Generate a valid TOTP 6-digit code for 2FA tests.
     */
    protected function currentTotpCode(string $secret): string
    {
        $authenticator = app(TotpAuthenticator::class);
        $reflection = new \ReflectionClass($authenticator);
        $decode = $reflection->getMethod('base32Decode');
        $decode->setAccessible(true);
        $hotp = $reflection->getMethod('hotp');
        $hotp->setAccessible(true);
        $binary = $decode->invoke($authenticator, $secret);

        return $hotp->invoke($authenticator, $binary, intdiv(time(), 30));
    }
}
