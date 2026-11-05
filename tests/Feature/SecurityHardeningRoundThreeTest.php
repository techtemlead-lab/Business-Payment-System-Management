<?php

namespace Tests\Feature;

use App\Domain\Notifications\PartnerEventSms;
use App\Models\Order;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityHardeningRoundThreeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => [], 'madina.login_otp.enabled' => false]);
    }

    public function test_account_staff_reset_sends_the_password_only_to_the_partner(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01712345678']);

        $response = $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$partner->id.'/reset-password', ['password' => 'ChosenByStaff123!']);

        $response->assertOk()
            ->assertJsonPath('data.password', null)
            ->assertJsonPath('data.can_reveal', false);
        $partner->refresh();
        $this->assertFalse(Hash::check('ChosenByStaff123!', $partner->password), 'Non-owner staff must not choose the password.');
        $this->assertCount(1, config('madina.sms.sent'));
        $this->assertSame('01712345678', config('madina.sms.sent')[0]['phone']);
    }

    public function test_changing_a_partner_phone_warns_the_old_number(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01712345678', 'dealer_code' => 'MNHC-7001']);

        $this->actingAs($manager)->onPortal('manage')
            ->patch('/commands/accounts/'.$partner->id, ['phone' => '01898765432', 'reason' => 'Dealer asked for a new number'])
            ->assertRedirect();

        $sent = collect(config('madina.sms.sent'))->firstWhere('event', 'account.phone_changed');
        $this->assertNotNull($sent);
        $this->assertSame('01712345678', $sent['phone']);
        $this->assertStringContainsString('MNHC-7001', $sent['message']);
    }

    public function test_featured_category_links_cannot_run_script(): void
    {
        SiteCustomization::save(['featured_categories_items' => "Herbal|fa-leaf|javascript:alert(1)\nTea|fa-mug|//evil.example\nOil|fa-tint|/products?category=oil"]);

        $links = array_column(SiteCustomization::getFeaturedCategories(), 'link', 'name');

        $this->assertSame('/products?category=Herbal', $links['Herbal']);
        $this->assertSame('/products?category=Tea', $links['Tea']);
        $this->assertSame('/products?category=oil', $links['Oil']);
    }

    public function test_staff_without_orders_or_finance_cannot_download_invoices(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $dealer = User::factory()->dealer()->create();
        $order = Order::create([
            'number' => 'ORD-SEC-'.Str::random(6),
            'user_id' => $dealer->id,
            'status' => 'submitted',
            'channel' => 'dealer',
            'currency' => 'BDT',
            'subtotal' => '100.00',
            'discount_amount' => '0.00',
            'tax_amount' => '0.00',
            'total' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'security-invoice'),
        ]);

        $this->actingAs($editor)->onPortal('manage')->get('/orders/'.$order->id.'/invoice')->assertForbidden();
    }

    public function test_readiness_probe_hides_environment_and_php_version(): void
    {
        $json = $this->get('/readyz')->json();

        $this->assertArrayNotHasKey('app_env', $json['checks']['environment'] ?? []);
        $this->assertArrayNotHasKey('php_version', $json['checks']['environment'] ?? []);
    }

    public function test_one_account_locks_after_many_failed_logins_from_different_addresses(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'email' => 'target@example.com']);

        for ($i = 1; $i <= 20; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.$i])->onPortal('dealer')
                ->post('/login', ['email' => 'target@example.com', 'password' => 'wrong-guess-'.$i]);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.1.99'])->onPortal('dealer')
            ->post('/login', ['email' => 'target@example.com', 'password' => 'password'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_public_website_texts_are_limited_per_number_and_to_bangladeshi_mobiles(): void
    {
        $sms = app(PartnerEventSms::class);

        for ($i = 0; $i < 5; $i++) {
            $sms->sendToPhone('01712345678', 'enquiry.public_received', 'Thanks');
        }
        $sms->sendToPhone('+44 7911 123456', 'enquiry.public_received', 'Thanks');

        $this->assertCount(PartnerEventSms::PublicPerPhonePerDay, config('madina.sms.sent'));
    }
}
