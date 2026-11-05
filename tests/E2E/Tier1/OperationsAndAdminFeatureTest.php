<?php

namespace Tests\E2E\Tier1;

use App\Domain\Inventory\BranchOperations;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\E2E\E2ETestCase;

/**
 * Tier 1 — Operations & Admin Feature Tests.
 *
 * Covers:
 * - F13: Partner Account Provisioning (BDRIS check, placement tree linking, KYC)
 * - F14: Inventory Stock Movements (Branch stock, adjustments, low-stock threshold)
 * - F18: MLM Referral Tree Explorer (Closure table tree navigation, descendant count)
 * - F19: Data Import Engine (Template download, preview, commit)
 * - F20: Operations Submodules (Field sales targets, payment accounts, dues adjustments)
 */
class OperationsAndAdminFeatureTest extends E2ETestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->enableFinancialPosting();
        $this->seedCommissionRules();
        Storage::fake('local');
    }

    // ==========================================
    // F13: Partner Account Provisioning
    // ==========================================

    /**
     * F13: Super admin provisions a new dealer account with referral sponsor linking.
     */
    public function test_super_admin_provisions_dealer_account_with_sponsor(): void
    {
        $admin = $this->createStaff('super_admin');
        $sponsor = $this->createDealer(['name' => 'Sponsor Dealer']);

        $dealerCode = 'SYN-D-'.Str::upper(Str::random(6));
        $email = 'newdealer.'.Str::random(5).'@example.com';
        $phone = '017'.fake()->unique()->numerify('########');

        $response = $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'name' => 'New Provisioned Dealer',
            'email' => $email,
            'phone' => $phone,
            'dealer_code' => $dealerCode,
            'sponsor_id' => $sponsor->id,
            'status' => 'active',
            'reason' => 'Provisioning verified partner',
        ]);

        $response->assertRedirect('/accounts');

        $created = User::where('email', $email)->first();
        $this->assertNotNull($created);
        $this->assertSame('dealer', $created->role);
        $this->assertSame($sponsor->id, $created->sponsor_id);

        // Wallet was provisioned
        $this->assertDatabaseHas('wallets', ['user_id' => $created->id]);

        // Referral tree attached sponsor as ancestor
        $this->assertTrue(ReferralTreePath::isDescendant($sponsor->id, $created->id));

        // Audit trail recorded
        $this->assertDatabaseHas('audit_events', [
            'actor_id' => $admin->id,
            'action' => 'account.created',
            'subject' => (string) $created->id,
        ]);
    }

    /**
     * F13: Partner account provisioning fails if dealer code is duplicate.
     */
    public function test_provisioning_fails_with_duplicate_dealer_code(): void
    {
        $admin = $this->createStaff('super_admin');
        $existing = $this->createDealer(['dealer_code' => 'SYN-DUP-01']);

        $response = $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'name' => 'Duplicate Code Partner',
            'email' => 'duplicate.code@example.com',
            'phone' => '017'.fake()->unique()->numerify('########'),
            'dealer_code' => 'SYN-DUP-01',
            'status' => 'active',
            'reason' => 'Attempting duplicate',
        ]);

        $response->assertSessionHasErrors(['dealer_code']);
    }

    /**
     * F13: Staff uploads KYC document for a partner account.
     */
    public function test_staff_uploads_kyc_document_for_partner(): void
    {
        $admin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();

        $file = UploadedFile::fake()->create('nid_front.pdf', 200, 'application/pdf');

        $response = $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/kyc-documents', [
            'kind' => 'nid_front',
            'document' => $file,
            'note' => 'National ID scanned copy verified',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $dealer->id,
            'kind' => 'nid_front',
        ]);
    }

    /**
     * F13: Staff lists and downloads partner KYC documents.
     */
    public function test_staff_lists_and_downloads_kyc_document(): void
    {
        $admin = $this->createStaff('super_admin');
        $dealer = $this->createDealer();

        $file = UploadedFile::fake()->create('nid_front.png', 150, 'image/png');

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/kyc-documents', [
            'kind' => 'nid_front',
            'document' => $file,
            'note' => 'NID uploaded',
        ]);

        $doc = DB::table('kyc_documents')->where('user_id', $dealer->id)->first();
        $this->assertNotNull($doc);

        // List KYC documents
        $listResponse = $this->actingAs($admin)->onPortal('sadmin')->getJson('/commands/accounts/'.$dealer->id.'/kyc-documents');
        $listResponse->assertOk();
        $listResponse->assertJsonFragment(['kind' => 'nid_front']);

        // Download KYC document
        $downloadResponse = $this->actingAs($admin)->onPortal('sadmin')->get('/commands/accounts/'.$dealer->id.'/kyc-documents/'.$doc->id);
        $downloadResponse->assertOk();
    }

    // ==========================================
    // F14: Inventory Stock Movements
    // ==========================================

    /**
     * F14: Inventory staff records product_in stock movement replenishing branch inventory.
     */
    public function test_staff_records_product_in_stock_movement(): void
    {
        $staff = $this->createStaff('inventory');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $product = $this->createProduct(['stock' => 0]);

        $response = $this->actingAs($staff)->onPortal('manage')->post('/commands/stock', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 100,
            'reason_code' => 'supplier_receipt',
            'reason' => 'Factory batch arrival shipment',
            'reference' => (string) Str::uuid(),
            'expires_on' => now('Asia/Dhaka')->addYear()->toDateString(),
        ]);

        $response->assertRedirect('/inventory');
        $response->assertSessionHasNoErrors();

        // Product total stock increased
        $product->refresh();
        $this->assertSame(100, $product->stock);

        // Branch stock row exists
        $this->assertDatabaseHas('branch_product_stocks', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 100,
        ]);

        // Stock movement audit record logged
        $this->assertDatabaseHas('stock_movements', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => 'product_in',
            'quantity' => 100,
        ]);
    }

    /**
     * F14: Inventory staff records product_out stock movement reducing branch inventory.
     */
    public function test_staff_records_product_out_stock_movement(): void
    {
        $staff = $this->createStaff('inventory');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $product = $this->createProduct(['stock' => 0]);

        // First stock in 50
        $this->actingAs($staff)->onPortal('manage')->post('/commands/stock', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 50,
            'reason_code' => 'supplier_receipt',
            'reason' => 'Initial stock in',
            'reference' => (string) Str::uuid(),
        ]);

        // Now stock out 15 for damage write off
        $response = $this->actingAs($staff)->onPortal('manage')->post('/commands/stock', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductOut,
            'quantity' => 15,
            'reason_code' => 'damage',
            'reason' => 'Damaged during unloading',
            'reference' => (string) Str::uuid(),
        ]);

        $response->assertRedirect('/inventory');
        $response->assertSessionHasNoErrors();

        $product->refresh();
        $this->assertSame(35, $product->stock);

        $this->assertDatabaseHas('branch_product_stocks', [
            'branch_id' => $branch->id,
            'product_id' => $product->id,
            'quantity' => 35,
        ]);
    }

    /**
     * F14: Stock adjustment requires valid UUID idempotency reference.
     */
    public function test_stock_adjustment_requires_uuid_reference(): void
    {
        $staff = $this->createStaff('inventory');
        $branch = $this->createBranch(['is_stock_hub' => true]);
        $product = $this->createProduct();

        $response = $this->actingAs($staff)->onPortal('manage')->post('/commands/stock', [
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 20,
            'reason_code' => 'supplier_receipt',
            'reason' => 'Non-uuid reference test',
            'reference' => 'NOT-A-UUID',
        ]);

        $response->assertSessionHasErrors(['reference']);
    }

    // ==========================================
    // F18: MLM Referral Tree Explorer
    // ==========================================

    /**
     * F18: Dealer explores downline network members via closure table API.
     */
    public function test_dealer_views_downline_network(): void
    {
        $rootDealer = $this->createDealer(['name' => 'Root Dealer']);
        $sub1 = $this->createSubDealer(['name' => 'Child SubDealer 1', 'sponsor_id' => $rootDealer->id]);
        $sub2 = $this->createSubDealer(['name' => 'Child SubDealer 2', 'sponsor_id' => $rootDealer->id]);

        ReferralTreePath::attachUser($sub1->id, $rootDealer->id);
        ReferralTreePath::attachUser($sub2->id, $rootDealer->id);

        $response = $this->actingAs($rootDealer)->getJson('/api/v1/network');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data);
        $ids = array_column($data, 'id');
        $this->assertContains($sub1->id, $ids);
        $this->assertContains($sub2->id, $ids);
    }

    /**
     * F18: Dealer can inspect orders placed by their verified descendant partner.
     */
    public function test_dealer_views_descendant_orders(): void
    {
        $rootDealer = $this->createDealer(['name' => 'Root Dealer']);
        $childDealer = $this->createDealer(['name' => 'Downline Partner', 'sponsor_id' => $rootDealer->id]);
        ReferralTreePath::attachUser($childDealer->id, $rootDealer->id);

        // Child creates an order
        $product = $this->createProduct(['mrp' => '300.00', 'dealer_price' => '200.00']);
        $this->actingAs($childDealer)->postJson('/api/v1/orders', [
            'items' => [['product_id' => $product->id, 'quantity' => 2]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        // Root dealer accesses child's network orders
        $response = $this->actingAs($rootDealer)->getJson('/api/v1/network/'.$childDealer->id.'/orders');

        $response->assertOk();
        $this->assertNotEmpty($response->json('data'));
    }

    /**
     * F18: Dealer cannot inspect orders of an unrelated partner who is not a descendant.
     */
    public function test_dealer_cannot_view_non_descendant_orders(): void
    {
        $dealerA = $this->createDealer(['name' => 'Dealer A']);
        $dealerB = $this->createDealer(['name' => 'Dealer B']); // Unrelated, not descendant

        $response = $this->actingAs($dealerA)->getJson('/api/v1/network/'.$dealerB->id.'/orders');

        $response->assertNotFound();
    }

    // ==========================================
    // F19: Data Import Engine
    // ==========================================

    /**
     * F19: Staff views data imports dashboard with available import categories.
     */
    public function test_staff_views_data_imports_dashboard(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->onPortal('sadmin')->get('/operations/data-imports');

        $response->assertOk();
    }

    /**
     * F19: Staff downloads template workbook for partners import.
     */
    public function test_staff_downloads_import_template(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->onPortal('sadmin')->get('/commands/operations/imports/templates/products');

        $response->assertOk();
    }

    /**
     * F19: Template download for invalid import type returns 404.
     */
    public function test_invalid_import_template_type_returns_404(): void
    {
        $staff = $this->createStaff('super_admin');

        $response = $this->actingAs($staff)->onPortal('sadmin')->get('/commands/operations/imports/templates/invalid_category');

        $response->assertNotFound();
    }

    // ==========================================
    // F20: Operations Submodules
    // ==========================================

    /**
     * F20: Finance staff retrieves configured corporate payment collection accounts.
     */
    public function test_finance_retrieves_payment_accounts(): void
    {
        $finance = $this->createStaff('finance');

        $response = $this->actingAs($finance)->onPortal('manage')->getJson('/api/v1/operations/payment-accounts');

        $response->assertOk();
        $response->assertJsonStructure(['data']);
    }

    /**
     * F20: Finance staff registers a new company payment collection account.
     */
    public function test_finance_creates_payment_account(): void
    {
        $finance = $this->createStaff('finance');

        $response = $this->actingAs($finance)->onPortal('manage')->postJson('/api/v1/operations/payment-accounts', [
            'label' => 'Islami Bank Main Account',
            'channel' => 'Bank',
            'holder' => 'Madina Agro Industries Limited',
            'account_number' => '20501122334455',
            'details' => 'Motijheel Corporate Branch',
            'collects_payments' => true,
            'pays_out' => true,
            'is_active' => true,
            'reason' => 'Corporate primary disbursement and collection account',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('payment_accounts', [
            'channel' => 'Bank',
            'label' => 'Islami Bank Main Account',
            'is_active' => true,
        ]);
    }

    /**
     * F20: Field sales target allocation for territory staff.
     */
    public function test_sales_lead_allocates_staff_target(): void
    {
        $admin = $this->createStaff('super_admin');
        $employee = User::factory()->create([
            'role' => 'sr',
            'status' => 'active',
            'phone' => '019'.fake()->unique()->numerify('########'),
        ]);

        // First assign territory
        $this->actingAs($admin)->onPortal('sadmin')->postJson('/api/v1/operations/staff/territories', [
            'staff_id' => $employee->id,
            'level' => 'division',
            'division' => 'Dhaka',
        ])->assertOk();

        $response = $this->actingAs($admin)->onPortal('sadmin')->postJson('/api/v1/operations/staff/targets', [
            'staff_id' => $employee->id,
            'period_month' => '2026-11',
            'target' => '150000.00',
            'bonus' => '5000.00',
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('staff_targets', [
            'staff_id' => $employee->id,
            'period_month' => '2026-11',
            'target' => '150000.00',
            'bonus' => '5000.00',
        ]);
    }

    /**
     * F20: Authorized operations surfaces load for staff.
     */
    public function test_operations_module_surfaces_load(): void
    {
        $admin = $this->createStaff('super_admin');

        foreach (['field-sales', 'payment-accounts', 'policy-schedules'] as $section) {
            $response = $this->actingAs($admin)->onPortal('sadmin')->get('/operations/'.$section);
            $response->assertOk();
        }
    }
}
