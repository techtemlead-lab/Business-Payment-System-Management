<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalInventorySmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return list<string>
     */
    private function dealerSections(): array
    {
        return [
            '/', '/orders', '/purchase', '/catalog', '/deliveries', '/dues',
            '/network', '/ranks', '/earnings', '/full-report', '/withdrawals',
            '/profile', '/security', '/notifications', '/circulars', '/help',
        ];
    }

    /**
     * @return list<string>
     */
    private function subdealerSections(): array
    {
        return [
            '/', '/orders', '/purchase', '/catalog', '/deliveries', '/dues',
            '/salary', '/earnings', '/full-report', '/withdrawals',
            '/profile', '/security', '/notifications', '/circulars', '/help',
        ];
    }

    /**
     * @return list<string>
     */
    private function staffCompanySections(): array
    {
        return [
            '/', '/employees', '/operations/staff-operations', '/operations/payroll', '/operations/my-payroll',
            '/operations/field-sales', '/operations/access-management',
            '/orders', '/deliveries', '/dues',
            '/treasury', '/payments', '/withdrawals', '/operations/payment-accounts', '/ledger', '/operations/dues-adjustments',
            '/operations/policy-schedules', '/operations/commission-holds', '/settlements', '/rewards', '/payouts-hub',
            '/operations/reserve-payouts', '/operations/finance-settings', '/operations/financial-readiness',
            '/catalog', '/inventory', '/branches', '/branch-performance', '/place-codes',
            '/reports', '/operations/financial-reports', '/dashboards', '/operations/data-imports',
            '/website', '/content', '/operations/video-marketing', '/enquiries', '/operations/service-integrations',
            '/audit', '/support-admin',
            '/profile', '/security', '/notifications', '/help',
            '/accounts', '/partnerships', '/commissions', '/policies', '/simulator', '/network-tree', '/area-dealers', '/ranks',
            '/sub-dealers', '/sub-dealer-orders', '/salary',
        ];
    }

    public function test_dealer_inventory_sections_respond_successfully(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        foreach ($this->dealerSections() as $path) {
            $this->actingAs($dealer)->onPortal('dealer')->get($path)
                ->assertOk("Dealer {$path}");
        }
    }

    public function test_subdealer_inventory_sections_respond_successfully(): void
    {
        $subdealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        foreach ($this->subdealerSections() as $path) {
            $this->actingAs($subdealer)->onPortal('subdealer')->get($path)
                ->assertOk("Sub-dealer {$path}");
        }
    }

    public function test_owner_inventory_sections_respond_successfully(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        foreach ([...$this->staffCompanySections(), '/benefits-fund'] as $path) {
            $response = $this->actingAs($owner)->onPortal('sadmin')->get($path);
            if ($path === '/sub-dealer-orders') {
                $response->assertRedirect();
                $this->assertStringContainsString('/orders', (string) $response->headers->get('Location'));

                continue;
            }
            $this->assertSame(
                200,
                $response->status(),
                "Owner {$path} returned {$response->status()} redirecting to ".$response->headers->get('Location')
            );
        }
    }

    public function test_finance_staff_accessible_inventory_sections_respond_successfully(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $paths = [
            '/', '/orders', '/deliveries', '/dues',
            '/treasury', '/payments', '/withdrawals', '/operations/payment-accounts', '/ledger', '/operations/dues-adjustments',
            '/operations/policy-schedules', '/operations/commission-holds', '/settlements', '/rewards', '/payouts-hub',
            '/operations/reserve-payouts', '/operations/finance-settings', '/operations/financial-readiness',
            '/catalog', '/reports', '/operations/financial-reports', '/operations/data-imports',
            '/commissions', '/policies', '/simulator', '/salary',
            '/profile', '/security', '/notifications', '/help',
        ];

        foreach ($paths as $path) {
            $this->actingAs($staff)->onPortal('manage')->get($path)
                ->assertOk("Finance {$path}");
        }
    }

    public function test_public_inventory_routes_respond_successfully(): void
    {
        $paths = [
            '/', '/products', '/dealers', '/about', '/contact', '/partnership', '/services', '/quality',
            '/faq', '/privacy', '/terms', '/notice', '/circular-training', '/circular-price', '/circular-meeting', '/login',
        ];

        foreach ($paths as $path) {
            $response = $this->get('http://'.$this->appDomain().$path);
            $this->assertSame(200, $response->status(), "Public {$path} returned {$response->status()}");
        }

        $this->get('http://'.$this->appDomain().'/join/TESTCODE')->assertRedirect();
    }

    private function appDomain(): string
    {
        return (string) (config('madina.app_domain') ?: 'localhost');
    }
}
