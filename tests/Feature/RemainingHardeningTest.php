<?php

namespace Tests\Feature;

use App\Domain\Audience\PublicPartnerApplication;
use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\MapsPresentation;
use App\Domain\Integrations\PaymentGatewayClient;
use App\Domain\Orders\OrderService;
use App\Models\DealerRank;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use App\Support\SpreadsheetCell;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RemainingHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_payment_accounts_api_rejects_content_only_staff(): void
    {
        $finance = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(PaymentAccounts::class)->save($finance, [
            'label' => 'Company bank',
            'channel' => 'Bank',
            'holder' => 'Madina',
            'account_number' => '987654321098',
            'details' => 'Private ops instructions',
            'collects_payments' => true,
            'pays_out' => false,
            'is_active' => true,
            'reason' => 'Hardening collection account for API tests.',
        ]);

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->getJson('/api/v1/operations/payment-accounts')->assertForbidden();

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->actingAs($dealer)->getJson('/api/v1/operations/payment-accounts')
            ->assertOk()
            ->assertJsonPath('data.0.account_number', '987654321098')
            ->assertJsonPath('data.0.secrets_revealed', true);
    }

    public function test_maps_public_config_never_exposes_api_keys(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(IntegrationSettings::class)->saveProvider($admin, 'maps', 'google', [
            'enabled' => true,
            'api_key' => 'browser-secret-key',
            'map_embed_url' => 'https://maps.google.com/maps?q=pirganj&output=embed',
        ], 0, 'Enable embed-only maps for public pages.');

        $public = app(IntegrationSettings::class)->mapsPublicConfig();
        $this->assertArrayNotHasKey('api_key', $public);
        $this->assertTrue($public['enabled']);
        $this->assertSame('https://maps.google.com/maps?q=pirganj&output=embed', $public['map_embed_url']);

        $maps = app(MapsPresentation::class)->forAddress('Thana Road, Pirganj');
        $this->assertNull($maps['script_url']);
        $this->assertSame('google_embed', $maps['provider']);
    }

    public function test_public_partner_application_can_be_submitted_and_reviewed(): void
    {
        $id = app(PublicPartnerApplication::class)->submit([
            'name' => 'Public Applicant',
            'email' => 'public.partner@example.test',
            'phone' => '01710001122',
            'role' => 'sub_dealer',
            'division' => 'Rangpur',
            'district' => 'Thakurgaon',
            'upazila' => 'Pirganj',
            'message' => 'Interested in sub-dealership coverage.',
        ]);

        $this->assertDatabaseHas('dealer_applications', [
            'id' => $id,
            'email' => 'public.partner@example.test',
            'status' => 'pending',
            'source' => 'public',
            'submitted_by' => null,
        ]);

        $this->post('/partnership/apply', [
            'name' => 'Second Applicant',
            'email' => 'second.partner@example.test',
            'phone' => '01710003344',
            'role' => 'dealer',
            'division' => 'Rangpur',
            'district' => 'Dinajpur',
            'website' => '',
        ])->assertRedirect('/partnership');

        $reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($reviewer)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Approved after territory and documents review.',
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'email' => 'public.partner@example.test',
            'role' => 'sub_dealer',
            'status' => 'active',
        ]);
        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'approved']);
    }

    public function test_settlement_request_key_is_idempotent(): void
    {
        $this->enableFinancialPosting();
        $key = (string) Str::uuid();
        $first = app(CommissionService::class)->settleMonthlySalaryPool('2024-01', null, $key);
        $second = app(CommissionService::class)->settleMonthlySalaryPool('2024-01', null, $key);

        $this->assertSame($first, $second);
        $this->assertSame('empty_pool', $first['status']);
        $this->assertSame(1, DB::table('commission_settlement_runs')->where('request_key', $key)->count());
    }

    public function test_rank_incentive_and_refund_request_keys_are_idempotent(): void
    {
        $this->enableFinancialPosting();
        $this->seed(CommissionRuleSeeder::class);
        $leader = User::factory()->create();
        DealerRank::create(['user_id' => $leader->id, 'current_rank' => 'mo', 'achieved_at' => now()]);
        $buyer = User::factory()->create();
        $product = Product::factory()->create(['mrp' => '500000.00', 'dealer_price' => '250000.00']);
        $order = app(OrderService::class)->create($buyer, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['paid' => '250000.00', 'status' => 'delivered']);
        app(CommissionService::class)->processOrder($order);

        $claimKey = (string) Str::uuid();
        $firstClaim = app(CommissionService::class)->claimOneTimeRankIncentive($leader->id, requestKey: $claimKey);
        $secondClaim = app(CommissionService::class)->claimOneTimeRankIncentive($leader->id, requestKey: $claimKey);
        $this->assertTrue($firstClaim['success']);
        $this->assertSame($firstClaim, $secondClaim);
        $this->assertSame(1, DB::table('rank_incentive_awards')->where('request_key', $claimKey)->count());

        $refundKey = (string) Str::uuid();
        $firstRefund = app(CommissionService::class)->reverseOrderPartial($order->fresh(), '1000.00', 'Idempotent refund', 'KEY-REFUND-1', null, $refundKey);
        $secondRefund = app(CommissionService::class)->reverseOrderPartial($order->fresh(), '1000.00', 'Idempotent refund', 'KEY-REFUND-1', null, $refundKey);
        $this->assertSame($firstRefund, $secondRefund);
        $this->assertSame(1, DB::table('commission_refunds')->where('request_key', $refundKey)->count());
    }

    public function test_user_mass_assignment_blocks_password_and_verified_at(): void
    {
        $user = User::factory()->create();
        $originalPassword = $user->password;
        $originalToken = $user->remember_token;
        $originalVerified = $user->email_verified_at;

        $user->update([
            'password' => 'ShouldNotStick1',
            'email_verified_at' => now()->addDay(),
            'remember_token' => 'token-should-not-stick',
            'name' => 'Guarded Name',
        ]);
        $user->refresh();

        $this->assertSame('Guarded Name', $user->name);
        $this->assertSame($originalPassword, $user->password);
        $this->assertEquals($originalVerified, $user->email_verified_at);
        $this->assertSame($originalToken, $user->remember_token);

        $user->forceFill(['password' => 'TrustedPass1a'])->save();
        $this->assertTrue(Hash::check('TrustedPass1a', $user->fresh()->password));
    }

    public function test_payment_gateway_lookup_uses_http_fake_without_match_when_disabled(): void
    {
        Http::fake();
        $result = app(PaymentGatewayClient::class)->lookup('bKash', 'TRX123', '100.00');
        $this->assertFalse($result['checked']);
        $this->assertFalse($result['matched']);
        Http::assertNothingSent();
    }

    public function test_spreadsheet_cells_neutralize_formula_prefixes(): void
    {
        $this->assertSame("'=1+1", SpreadsheetCell::text('=1+1'));
        $this->assertSame("'+cmd", SpreadsheetCell::text('+cmd'));
        $this->assertSame('Ordinary', SpreadsheetCell::text('Ordinary'));
    }

    public function test_catalog_hides_stock_and_dealer_prices_from_content_staff(): void
    {
        $product = Product::factory()->create([
            'active' => true,
            'stock' => 42,
            'low_stock_at' => 5,
            'name' => 'Hardening Tonic',
            'sku' => 'HARD-1',
            'category' => 'Test',
            'mrp' => '200.00',
            'dealer_price' => '140.00',
            'sub_dealer_price' => '150.00',
        ]);

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.products.0.id', $product->id)
                ->where('data.products.0.stock_status', $product->stockStatus())
                ->missing('data.products.0.stock')
                ->missing('data.products.0.price')
                ->missing('data.all_catalog_products.0.dealer_price')
                ->missing('data.all_catalog_products.0.stock'));

        $this->actingAs($editor)->getJson('/api/v1/catalog')
            ->assertOk()
            ->assertJsonMissingPath('data.0.stock')
            ->assertJsonPath('data.0.stock_status', $product->stockStatus());

        $this->actingAs($editor)->onPortal('manage')->get('/products/'.$product->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->missing('product.stock')
                ->missing('product.dealer_price')
                ->missing('product.sub_dealer_price')
                ->where('product.stock_status', $product->stockStatus()));
    }

    public function test_public_product_payloads_expose_status_not_counts(): void
    {
        $product = Product::factory()->create([
            'active' => true,
            'featured' => true,
            'stock' => 31415,
            'name' => 'Public Status Only',
            'mrp' => '250.00',
            'dealer_price' => '125.00',
        ]);

        $card = SiteCustomization::formatPublicProductCard($product);
        $this->assertArrayNotHasKey('stock', $card);
        $this->assertSame($product->stockStatus(), $card['stock_status']);

        $this->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('In Stock')
            ->assertDontSee('31415');
    }

    public function test_inventory_staff_can_update_locale_preference(): void
    {
        $inventory = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $this->actingAs($inventory)->onPortal('manage')->post('/locale', ['locale' => 'bn'])
            ->assertRedirect();
        $this->assertSame('bn', $inventory->fresh()->locale);
    }

    public function test_account_manager_can_use_identity_lookup_endpoints(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);
        $this->actingAs($manager)->onPortal('manage')->postJson('/commands/identity/bdris-lookup', [
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
        ])->assertOk()->assertJsonPath('data.source', 'disabled');
    }
}
