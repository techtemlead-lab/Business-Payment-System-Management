<?php

namespace Tests\Feature;

use App\Domain\Identity\BdrisLookup;
use App\Domain\Integrations\CourierGatewayClient;
use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\PaymentGatewayClient;
use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ServiceIntegrationsLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_integration_catalog_exposes_bangla_group_copy(): void
    {
        app()->setLocale('bn');

        $groups = IntegrationCatalog::groups();

        $this->assertSame('SMS গেটওয়ে', $groups['sms']['label'] ?? null);
        $this->assertStringContainsString('অ্যাকাউন্ট তৈরি', $groups['sms']['description'] ?? '');
        $this->assertSame('পেমেন্ট গেটওয়ে API', $groups['payments']['label'] ?? null);
        $this->assertSame(__('portal.si_provider_pathao', [], 'bn'), $groups['couriers']['providers']['pathao']['label'] ?? null);
        $this->assertSame(__('portal.si_provider_bkash', [], 'bn'), $groups['payments']['providers']['bkash']['label'] ?? null);
        $this->assertSame(__('portal.si_provider_ssl_wireless', [], 'bn'), $groups['sms']['providers']['ssl_wireless']['label'] ?? null);
        $this->assertSame(__('portal.si_provider_rocket', [], 'bn'), $groups['payments']['providers']['rocket']['label'] ?? null);
    }

    public function test_enabling_bdris_without_credentials_returns_bangla_error(): void
    {
        app()->setLocale('bn');
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $settings = app(IntegrationSettings::class);
        $version = $settings->snapshot()['version'];

        try {
            $settings->saveProvider($admin, 'bdris', 'bdris', [
                'enabled' => true,
                'base_url' => '',
                'api_key' => '',
            ], $version, 'Attempt enable without credentials');
            $this->fail('Expected ValidationException for incomplete BDRIS credentials');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'অটোফিল চালু করার আগে BDRIS এন্ডপয়েন্ট URL ও API কী দিন।',
                $exception->errors()['base_url'][0] ?? null,
            );
        }
    }

    public function test_disabled_payment_gateway_lookup_returns_bangla_message(): void
    {
        app()->setLocale('bn');

        $result = app(PaymentGatewayClient::class)->lookup('bKash', 'TRX123', '100.00');

        $this->assertFalse($result['checked']);
        $this->assertSame(__('portal.si_pay_none_enabled', [], 'bn'), $result['message']);
    }

    public function test_sms_gateway_none_accepted_message_uses_locale(): void
    {
        app()->setLocale('bn');

        $result = app(SmsGatewayClient::class)->send('01700000000', 'Locale smoke');

        $this->assertFalse($result['sent']);
        $this->assertSame(__('portal.si_sms_none_accepted', [], 'bn'), $result['message']);
    }

    public function test_disabled_courier_create_returns_bangla_message(): void
    {
        app()->setLocale('bn');

        $result = app(CourierGatewayClient::class)->create('pathao', [
            'recipient_name' => 'Test',
            'recipient_phone' => '01710000000',
            'recipient_address' => 'Dhaka',
            'amount' => '100',
            'invoice' => 'INV-1',
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(__('portal.si_courier_disabled', [], 'bn'), $result['message']);
    }

    public function test_bdris_lookup_returns_bangla_validation_and_disabled_messages(): void
    {
        app()->setLocale('bn');
        $lookup = app(BdrisLookup::class);

        $missing = $lookup->fetch('', '');
        $this->assertFalse($missing['success']);
        $this->assertSame(__('portal.acct_bdris_need_fields', [], 'bn'), $missing['message']);

        $disabled = $lookup->fetch('19901234567890123', '1990-01-01');
        $this->assertFalse($disabled['success']);
        $this->assertSame(__('portal.acct_bdris_disabled', [], 'bn'), $disabled['message']);
    }
}
