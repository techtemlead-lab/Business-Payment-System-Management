<?php

namespace Tests\Feature;

use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenealogyExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_can_export_own_downline_genealogy_csv(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'dealer_code' => 'D-ROOT']);
        $child = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => $dealer->id,
            'dealer_code' => 'D-CHILD',
            'name' => 'Downline Partner',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($child->id, $dealer->id);

        $response = $this->actingAs($dealer)->onPortal('dealer')->get('/exports/genealogy');

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=utf-8');
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Dealer code', $csv);
        $this->assertStringContainsString('D-CHILD', $csv);
        $this->assertStringContainsString('Downline Partner', $csv);
        $this->assertDoesNotMatchRegularExpression('/^D-ROOT,/m', $csv);
    }

    public function test_dealer_genealogy_export_respects_network_toggle(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($dealer->id, null);
        DashboardManagement::save(['dealer_network_tree_enabled' => false]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/exports/genealogy')
            ->assertForbidden();
    }

    public function test_staff_with_accounts_can_export_company_genealogy_csv(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'D-STAFF',
            'name' => 'Company Dealer',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);

        $response = $this->actingAs($admin)->onPortal('sadmin')->get('/exports/genealogy');

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('D-STAFF', $csv);
        $this->assertStringContainsString('Company Dealer', $csv);
    }

    public function test_sub_dealer_cannot_export_genealogy(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        ReferralTreePath::attachUser($sub->id, null);

        $this->actingAs($sub)->onPortal('subdealer')->get('/exports/genealogy')
            ->assertForbidden();
    }
}
