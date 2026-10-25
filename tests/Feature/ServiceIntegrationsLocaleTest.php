<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;
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
}
