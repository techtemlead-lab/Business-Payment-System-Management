<?php

namespace Tests\Feature;

use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Branch staff see only their own branch's orders. With no branch their lists are empty,
 * so the page must say why instead of looking broken.
 */
class StaffWithNoBranchNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function orderLists(): array
    {
        return ['orders' => ['/orders'], 'deliveries' => ['/deliveries']];
    }

    #[DataProvider('orderLists')]
    public function test_staff_with_no_branch_are_told_why_the_list_is_empty(string $address): void
    {
        $this->actingAs(User::factory()->staff('support')->create())->onPortal('manage')->get($address)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.no_branch_assigned', true));
    }

    #[DataProvider('orderLists')]
    public function test_staff_with_a_branch_are_not(string $address): void
    {
        $support = User::factory()->staff('support')->create();
        BranchStaffAssignment::query()->create(['branch_id' => CompanyBranch::factory()->create()->id, 'user_id' => $support->id, 'duty' => 'storekeeper', 'is_primary' => true]);

        $this->actingAs($support)->onPortal('manage')->get($address)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.no_branch_assigned', false));
    }

    public function test_people_who_see_every_branch_are_not(): void
    {
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->get('/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.no_branch_assigned', false));
    }
}
