<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\IntegrationUrlPolicy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * SA-03: an integration may only send its stored secrets and customer data to its own provider,
 * over https, and never to an internal address.
 */
class IntegrationAddressRulesTest extends TestCase
{
    use RefreshDatabase;

    private function save(User $actor, string $portal, array $fields)
    {
        return $this->actingAs($actor)->onPortal($portal)->post('/commands/operations/integrations/providers', [
            'version' => app(IntegrationSettings::class)->snapshot(true)['version'],
            'reason' => 'Courier settings check for the security test.',
            ...$fields,
        ]);
    }

    public function test_finance_staff_cannot_point_a_courier_at_their_own_server(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        foreach (['https://attacker.example.com/aladdin', 'http://api-hermes.pathao.com', 'https://pathao.com.attacker.net'] as $url) {
            $this->save($finance, 'manage', [
                'group' => 'couriers', 'provider' => 'pathao', 'enabled' => false, 'base_url' => $url,
            ])->assertSessionHasErrors('base_url');
        }

        $this->save($finance, 'manage', [
            'group' => 'couriers', 'provider' => 'pathao', 'enabled' => false, 'base_url' => 'https://courier-api-sandbox.pathao.com',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_new_courier_address_drops_the_saved_secrets(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->save($owner, 'sadmin', [
            'group' => 'couriers', 'provider' => 'pathao', 'enabled' => false, 'base_url' => 'https://api-hermes.pathao.com',
            'api_key' => 'client-id', 'api_secret' => 'client-secret', 'username' => 'office@example.com', 'password' => 'courier-pass',
        ])->assertSessionHasNoErrors();

        $this->save($owner, 'sadmin', [
            'group' => 'couriers', 'provider' => 'pathao', 'enabled' => false, 'base_url' => 'https://courier-api-sandbox.pathao.com',
        ])->assertSessionHasNoErrors();

        $saved = app(IntegrationSettings::class)->provider('couriers', 'pathao');
        $this->assertSame('https://courier-api-sandbox.pathao.com', $saved['base_url']);
        foreach (['api_key', 'api_secret', 'username', 'password'] as $secret) {
            $this->assertSame('', (string) $saved[$secret], $secret);
        }
    }

    public function test_the_same_address_keeps_the_saved_secrets(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $fields = ['group' => 'couriers', 'provider' => 'redx', 'enabled' => false, 'base_url' => 'https://openapi.redx.com.bd'];
        $this->save($owner, 'sadmin', [...$fields, 'api_key' => 'redx-token'])->assertSessionHasNoErrors();
        $this->save($owner, 'sadmin', [...$fields, 'api_key' => '••••••••'])->assertSessionHasNoErrors();

        $this->assertSame('redx-token', app(IntegrationSettings::class)->provider('couriers', 'redx')['api_key']);
    }

    public function test_the_map_box_takes_only_a_google_embed_address(): void
    {
        $finance = User::factory()->create(['role' => 'finance', 'status' => 'active']);
        $this->save($finance, 'manage', [
            'group' => 'maps', 'provider' => 'google', 'enabled' => false, 'map_embed_url' => 'https://evil.example.com/frame',
        ])->assertSessionHasErrors('map_embed_url');
        $this->save($finance, 'manage', [
            'group' => 'maps', 'provider' => 'google', 'enabled' => false, 'map_embed_url' => 'https://www.google.com/maps/embed?pb=abc',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_provider_without_its_own_domain_refuses_internal_addresses(): void
    {
        $policy = new IntegrationUrlPolicy(fn (string $host): array => match ($host) {
            'gateway.example.org' => ['93.184.216.34'],
            'inside.example.org' => ['10.0.0.5'],
            default => [],
        });
        $defaults = ['base_url' => '', 'balance_url' => ''];

        $policy->assertAllowed('sms', $defaults, ['base_url' => 'https://gateway.example.org/send']);
        foreach (['https://inside.example.org/send', 'https://127.0.0.1/send', 'https://169.254.169.254/latest', 'https://[::1]/x', 'https://100.64.1.1/x', 'https://[::ffff:10.0.0.1]/x', 'https://nowhere.example.org'] as $url) {
            try {
                $policy->assertAllowed('sms', $defaults, ['base_url' => $url]);
                $this->fail('Accepted '.$url);
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('base_url', $exception->errors());
            }
        }
    }

    public function test_the_provider_domain_comes_from_its_default_address(): void
    {
        $policy = new IntegrationUrlPolicy;
        $this->assertSame(['pathao.com'], $policy->providerDomains(['base_url' => 'https://api-hermes.pathao.com']));
        $this->assertSame(['redx.com.bd'], $policy->providerDomains(['base_url' => 'https://openapi.redx.com.bd']));
        $this->assertSame(['sms.net.bd'], $policy->providerDomains(['base_url' => 'https://api.sms.net.bd/sendsms', 'balance_url' => 'https://api.sms.net.bd/user/balance/']));
        $this->assertSame(['mynagad.com'], $policy->providerDomains(['base_url' => 'https://api.mynagad.com:10443']));
    }
}
