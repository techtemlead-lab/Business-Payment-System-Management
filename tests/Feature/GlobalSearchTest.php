<?php

namespace Tests\Feature;

use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\Order;
use App\Models\PrescriptionPatient;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The office search box: each group follows the person's permissions and branch,
 * phone numbers come back masked, and patients are never searched at all.
 */
class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $dhaka;

    private CompanyBranch $rajshahi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dhaka = CompanyBranch::factory()->create(['code' => 'DHK-01', 'name' => 'Dhaka Hub']);
        $this->rajshahi = CompanyBranch::factory()->create(['code' => 'RAJ-01', 'name' => 'Rajshahi Hub']);
    }

    public function test_the_owner_finds_orders_partners_and_products(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create(['name' => 'Findme Rahim', 'dealer_code' => 'MD-777']);
        $order = $this->orderFor($dealer, $this->dhaka, 'ORD-FINDME-1');
        Product::factory()->create(['name' => 'Findme Tonic', 'sku' => 'SKU-FINDME']);

        $results = $this->search($owner, 'findme', 'sadmin')->assertOk();

        $results->assertJsonPath('data.orders.0.id', (string) $order->id);
        $results->assertJsonPath('data.partners.0.code', 'MD-777');
        $results->assertJsonPath('data.products.0.sku', 'SKU-FINDME');
    }

    public function test_it_finds_partners_by_code_and_by_phone_digits(): void
    {
        $owner = User::factory()->staff()->create();
        User::factory()->dealer()->create(['name' => 'By Code', 'dealer_code' => 'CODE-4411', 'phone' => '01700000001']);
        User::factory()->dealer()->create(['name' => 'By Phone', 'phone' => '01712349876']);

        $this->search($owner, 'code-4411', 'sadmin')->assertJsonPath('data.partners.0.name', 'By Code');
        $this->search($owner, '01712349876', 'sadmin')->assertJsonPath('data.partners.0.name', 'By Phone');
        $this->search($owner, '8801712349876', 'sadmin')->assertJsonPath('data.partners.0.name', 'By Phone');
        $this->search($owner, '০১৭১২৩৪৯৮৭৬', 'sadmin')->assertJsonPath('data.partners.0.name', 'By Phone');
    }

    public function test_phone_numbers_are_masked_even_for_the_owner(): void
    {
        $owner = User::factory()->staff()->create();
        User::factory()->dealer()->create(['name' => 'Masked Partner', 'phone' => '01755556666']);

        $response = $this->search($owner, 'masked partner', 'sadmin')->assertOk();

        $response->assertJsonPath('data.partners.0.phone', '••••6666');
        $this->assertStringNotContainsString('01755556666', $response->getContent());
    }

    public function test_each_group_needs_its_own_permission(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Perm Partner']);
        $this->orderFor($dealer, null, 'ORD-PERM-1');
        Product::factory()->create(['name' => 'Perm Syrup']);

        $accountsOnly = $this->employee(['accounts']);
        $this->search($accountsOnly, 'perm')
            ->assertJsonCount(1, 'data.partners')
            ->assertJsonCount(0, 'data.orders')
            ->assertJsonCount(0, 'data.products');

        $catalogOnly = $this->employee(['catalog']);
        $this->search($catalogOnly, 'perm')
            ->assertJsonCount(0, 'data.partners')
            ->assertJsonCount(0, 'data.orders')
            ->assertJsonCount(1, 'data.products');
    }

    public function test_partners_cannot_use_the_office_search(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Search Blocked']);

        $this->actingAs($dealer)->onPortal('dealer')->getJson('/commands/search?q=search')->assertForbidden();
    }

    public function test_orders_stay_inside_the_staff_members_branch(): void
    {
        $dealer = User::factory()->dealer()->create();
        $inside = $this->orderFor($dealer, $this->dhaka, 'ORD-BRANCH-1');
        $this->orderFor($dealer, $this->rajshahi, 'ORD-BRANCH-2');
        $this->orderFor($dealer, null, 'ORD-BRANCH-3');
        $staff = $this->employee(['orders']);
        BranchStaffAssignment::query()->create(['user_id' => $staff->id, 'branch_id' => $this->dhaka->id, 'is_primary' => true, 'active' => true]);

        $this->search($staff, 'ord-branch')
            ->assertJsonCount(1, 'data.orders')
            ->assertJsonPath('data.orders.0.id', (string) $inside->id);
        $this->actingAs($staff)->onPortal('manage')->getJson('/commands/search?q=ord-branch&branch_id='.$this->rajshahi->id)->assertForbidden();
    }

    public function test_partners_follow_the_branch_picked_in_the_top_bar(): void
    {
        $inside = User::factory()->dealer()->create(['name' => 'Scoped Inside', 'active_branch_id' => $this->dhaka->id]);
        User::factory()->dealer()->create(['name' => 'Scoped Outside', 'active_branch_id' => $this->rajshahi->id]);
        $staff = $this->employee(['accounts']);
        $staff->forceFill(['active_branch_id' => $this->dhaka->id])->save();

        $this->search($staff, 'scoped')
            ->assertJsonCount(1, 'data.partners')
            ->assertJsonPath('data.partners.0.id', $inside->id);
        $this->actingAs($staff)->onPortal('manage')->getJson('/commands/search?q=scoped&all_branches=1')
            ->assertJsonCount(2, 'data.partners');
    }

    public function test_patients_are_never_returned(): void
    {
        $owner = User::factory()->staff()->create();
        $prescriber = User::factory()->dealer()->create(['name' => 'Doctor Karim']);
        PrescriptionPatient::factory()->create(['prescriber_id' => $prescriber->id, 'name' => 'Patient Zarina', 'phone' => '01899998888']);

        $byName = $this->search($owner, 'zarina', 'sadmin')->assertOk();
        $byPhone = $this->search($owner, '01899998888', 'sadmin')->assertOk();

        foreach ([$byName, $byPhone] as $response) {
            $response->assertJsonCount(0, 'data.orders')->assertJsonCount(0, 'data.partners')->assertJsonCount(0, 'data.products');
            $this->assertStringNotContainsString('Zarina', $response->getContent());
        }
    }

    public function test_search_is_rate_limited(): void
    {
        $owner = User::factory()->staff()->create();
        for ($attempt = 0; $attempt < 40; $attempt++) {
            $this->search($owner, 'limit', 'sadmin')->assertOk();
        }

        $this->search($owner, 'limit', 'sadmin')->assertStatus(429);
    }

    /** @param list<string> $permissions */
    private function employee(array $permissions): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => $permissions]]);
    }

    private function search(User $actor, string $term, string $portal = 'manage'): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)->getJson('/commands/search?q='.urlencode($term));
    }

    private function orderFor(User $buyer, ?CompanyBranch $branch, string $number): Order
    {
        return Order::create([
            'number' => $number,
            'user_id' => $buyer->id,
            'status' => 'submitted',
            'total' => '100.00',
            'fulfillment_branch_id' => $branch?->id,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
    }
}
