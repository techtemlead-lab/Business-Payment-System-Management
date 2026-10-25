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

    public function test_bangla_dealer_genealogy_csv_uses_partner_locale_headers(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'D-BN-ROOT',
            'locale' => 'bn',
        ]);
        $child = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => $dealer->id,
            'dealer_code' => 'D-BN-CHILD',
            'name' => 'BN Downline',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($child->id, $dealer->id);

        $csv = $this->actingAs($dealer)->onPortal('dealer')
            ->get('/exports/genealogy')
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString(__('portal.csv_col_dealer_code', [], 'bn'), $csv);
        $this->assertStringContainsString(__('portal.csv_col_lifetime_purchases_bdt', [], 'bn'), $csv);
        $this->assertStringNotContainsString('Dealer code', $csv);
        $this->assertStringContainsString('D-BN-CHILD', $csv);
        $this->assertStringContainsString(__('portal.acct_role_dealer', [], 'bn'), $csv);
        $this->assertStringContainsString(__('portal.status_active', [], 'bn'), $csv);
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

    public function test_dealer_network_exposes_visual_tree_nodes_with_full_phone(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01711111111',
            'dealer_code' => 'D-TREE',
        ]);
        $child = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => $dealer->id,
            'phone' => '01822222222',
            'dealer_code' => 'D-LEAF',
        ]);
        ReferralTreePath::attachUser($dealer->id, null);
        ReferralTreePath::attachUser($child->id, $dealer->id);

        $this->actingAs($dealer)->onPortal('dealer')->get('/network')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', 'network')
                ->has('data.dealers', 2)
                ->where('data.dealers', fn ($dealers) => collect($dealers)->contains(fn ($row) => ($row['dealer_code'] ?? null) === 'D-LEAF' && ($row['phone'] ?? null) === '01822222222')
                    && collect($dealers)->contains(fn ($row) => ($row['dealer_code'] ?? null) === 'D-TREE' && ($row['phone'] ?? null) === '01711111111')));
    }

    public function test_staff_network_tree_masks_partner_phones(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01933333333',
            'dealer_code' => 'D-MASK',
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->get('/network-tree')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', 'network-tree')
                ->where('data.dealers', fn ($dealers) => collect($dealers)->contains(fn ($row) => ($row['dealer_code'] ?? null) === 'D-MASK' && ($row['phone'] ?? null) === '••••3333')));
    }
}
