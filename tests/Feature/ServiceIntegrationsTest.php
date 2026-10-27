<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ServiceIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlaceCodeSeeder::class);
        config(['madina.financial_activation' => false, 'madina.bdris.enabled' => false]);
    }

    public function test_integrations_page_lists_providers_disabled_by_default(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/operations/service-integrations')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('ServiceIntegrations')
                ->where('integrations.groups.bdris.providers.bdris.enabled', false)
                ->where('integrations.groups.sms.providers.ssl_wireless.enabled', false)
                ->where('integrations.groups.mail.providers.smtp.enabled', false)
                ->where('integrations.groups.maps.providers.google.enabled', false)
                ->where('integrations.groups.couriers.providers.pathao.enabled', false)
                ->where('integrations.groups.payments.providers.bkash.enabled', false)
                ->where('canEdit', true));
    }

    public function test_admin_can_enable_bdris_with_endpoint_and_key(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'bdris',
            'provider' => 'bdris',
            'version' => 0,
            'enabled' => true,
            'base_url' => 'https://bdris.example/api',
            'api_key' => 'secret-key',
            'timeout' => 10,
            'reason' => 'Enable birth registration lookup for onboarding.',
        ])->assertRedirect();

        $settings = app(IntegrationSettings::class);
        $this->assertTrue($settings->isEnabled('bdris', 'bdris'));
        $this->assertSame('https://bdris.example/api', $settings->provider('bdris', 'bdris')['base_url']);
        $this->assertSame('secret-key', $settings->provider('bdris', 'bdris')['api_key']);

        Http::fake([
            'https://bdris.example/api/birth-registrations/lookup*' => Http::response([
                'data' => ['name' => 'Test User', 'name_bn' => 'টেস্ট', 'father_name' => 'Father', 'mother_name' => 'Mother', 'occupation' => 'Dealer'],
            ], 200),
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->postJson('/commands/identity/bdris-lookup', [
            'birth_certificate_number' => '12345678901234567',
            'date_of_birth' => '1990-01-01',
        ])->assertOk()->assertJsonPath('data.success', true)->assertJsonPath('data.details.name', 'Test User');
    }

    public function test_bdris_button_hidden_when_disabled(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->get('/accounts/create')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AccountCreate')
                ->where('bdris_enabled', false));
    }

    public function test_enabled_sms_provider_is_used_before_log_driver(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'custom',
            'version' => 0,
            'enabled' => true,
            'base_url' => 'https://sms.example/send',
            'api_key' => 'sms-key',
            'sender_id' => 'MADINA',
            'reason' => 'Enable custom SMS gateway for account notices.',
        ])->assertRedirect();

        Http::fake(['https://sms.example/send' => Http::response(['ok' => true], 200)]);

        $result = app(SmsGatewayClient::class)->send('01700000000', 'Hello Madina');
        $this->assertTrue($result['sent']);
        $this->assertSame('custom', $result['provider']);
        Http::assertSent(fn ($request) => $request->url() === 'https://sms.example/send');
    }

    public function test_cannot_enable_courier_without_credentials(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'couriers',
            'provider' => 'steadfast',
            'version' => 0,
            'enabled' => true,
            'base_url' => '',
            'api_key' => '',
            'reason' => 'Attempt to enable courier without credentials.',
        ])->assertSessionHasErrors(['base_url']);
    }

    public function test_admin_can_enable_smtp_mailer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'mail',
            'provider' => 'smtp',
            'version' => 0,
            'enabled' => true,
            'host' => 'smtp.example.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mailer@example.com',
            'password' => 'secret-pass',
            'from_address' => 'noreply@example.com',
            'from_name' => 'Madina',
            'reason' => 'Enable SMTP for credential delivery fallback.',
        ])->assertRedirect();

        $settings = app(IntegrationSettings::class);
        $this->assertTrue($settings->isEnabled('mail', 'smtp'));
        $this->assertSame('smtp.example.com', $settings->provider('mail', 'smtp')['host']);
        $this->assertSame('noreply@example.com', $settings->provider('mail', 'smtp')['from_address']);
    }

    public function test_sms_balance_endpoint_reads_provider_balance(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'custom',
            'version' => 0,
            'enabled' => true,
            'base_url' => 'https://sms.example/send',
            'balance_url' => 'https://sms.example/balance',
            'api_key' => 'sms-key',
            'sender_id' => 'MADINA',
            'reason' => 'Enable custom SMS gateway with balance endpoint.',
        ])->assertRedirect();

        Http::fake(['https://sms.example/balance' => Http::response(['balance' => 1250], 200)]);

        $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/operations/integrations/sms-balance?provider=custom')
            ->assertOk()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.balance', '1250');
    }
}
