<?php

namespace Tests\Feature;

use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerFullReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_full_report_section_includes_orders_commissions_and_team_activity(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $downline = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($downline->id, $dealer->id);

        $product = Product::factory()->create([
            'mrp' => '200.00',
            'dealer_price' => '100.00',
            'active' => true,
            'stock' => 500,
        ]);
        $order = app(OrderService::class)->create($dealer, [
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $order->update(['paid' => '300.00', 'status' => 'delivered']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/full-report')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'full-report')
                ->has('data.full_report.summary')
                ->where('data.full_report.summary.orders_count', 1)
                ->has('data.full_report.orders', 1)
                ->has('data.full_report.team_members', 1)
                ->where('data.full_report.team_members.0.id', $downline->id));
    }

    public function test_sub_dealer_can_open_full_report(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sub->id, null);

        $this->actingAs($sub)->onPortal('subdealer')
            ->get('/full-report')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/subdealer/Dashboard')
                ->where('section', 'full-report')
                ->has('data.full_report.summary'));
    }

    public function test_dealer_can_download_full_report_pdf(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-PDF-1',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/full-report/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Madina-partner-report-MNHC-PDF-1.pdf');
    }

    public function test_staff_cannot_download_partner_full_report_pdf(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->get('/full-report/pdf')
            ->assertNotFound();
    }
}
