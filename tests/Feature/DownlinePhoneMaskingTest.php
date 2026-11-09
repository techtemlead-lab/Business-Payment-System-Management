<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerFullReport;
use App\Models\ReferralTreePath;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Owner decision: a dealer sees full phone numbers only for members they referred directly;
 * for everyone deeper in the downline only the last four digits. Staff views are unchanged.
 */
class DownlinePhoneMaskingTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private User $child;

    private User $grandchild;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->root = User::factory()->dealer()->create(['status' => 'active', 'phone' => '+8801711000001', 'dealer_code' => 'D-ROOT']);
        $this->child = User::factory()->dealer()->create(['status' => 'active', 'phone' => '+8801711000002', 'sponsor_id' => $this->root->id, 'dealer_code' => 'D-CHILD']);
        $this->grandchild = User::factory()->subDealer()->create(['status' => 'active', 'phone' => '+8801711005678', 'sponsor_id' => $this->child->id, 'dealer_code' => 'S-GRAND']);
        ReferralTreePath::attachUser($this->root->id, null);
        ReferralTreePath::attachUser($this->child->id, $this->root->id);
        ReferralTreePath::attachUser($this->grandchild->id, $this->child->id);
    }

    /** @return array<int, string> */
    private function nodePhones(User $viewer, string $portal): array
    {
        $phones = [];
        $this->actingAs($viewer)->onPortal($portal)->get($portal === 'sadmin' ? '/network-tree' : '/network')->assertOk()
            ->assertInertia(function (Assert $page) use (&$phones): void {
                foreach ($page->toArray()['props']['data']['dealers'] as $node) {
                    $phones[(int) $node['id']] = (string) $node['phone'];
                }
            });

        return $phones;
    }

    public function test_the_dealer_tree_shows_full_numbers_only_for_direct_referrals(): void
    {
        $phones = $this->nodePhones($this->root, 'dealer');

        $this->assertSame('+8801711000001', $phones[$this->root->id], 'The dealer sees their own number.');
        $this->assertSame('+8801711000002', $phones[$this->child->id]);
        $this->assertSame('••••5678', $phones[$this->grandchild->id]);

        // The child is the grandchild's direct sponsor, so the child sees the full number.
        $childView = $this->nodePhones($this->child, 'dealer');
        $this->assertSame('+8801711005678', $childView[$this->grandchild->id]);
    }

    public function test_the_downline_export_follows_the_same_rule_and_staff_exports_are_unchanged(): void
    {
        $csv = $this->actingAs($this->root)->onPortal('dealer')->get('/exports/genealogy')->assertOk()->streamedContent();
        $this->assertStringContainsString('+8801711000002', $csv);
        $this->assertStringContainsString('••••5678', $csv);
        $this->assertStringNotContainsString('+8801711005678', $csv);

        $staff = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts']]]);
        $company = $this->actingAs($staff)->onPortal('manage')->get('/exports/genealogy')->assertOk()->streamedContent();
        $this->assertStringContainsString('+8801711005678', $company);
    }

    public function test_the_full_report_team_list_follows_the_same_rule(): void
    {
        // The full report page is gone; its PDF, still on /full-report/pdf, prints this same list.
        $phones = collect(app(PartnerFullReport::class)->for($this->root)['team_members'])->pluck('phone', 'id');

        $this->assertSame('+8801711000002', $phones[$this->child->id]);
        $this->assertSame('••••5678', $phones[$this->grandchild->id]);
    }

    public function test_staff_tree_view_is_unchanged(): void
    {
        $owner = User::factory()->staff()->create();
        $phones = $this->nodePhones($owner, 'sadmin');

        $this->assertSame('••••0002', $phones[$this->child->id]);
        $this->assertSame('••••5678', $phones[$this->grandchild->id]);
    }

    public function test_the_masking_helper(): void
    {
        $this->assertSame('••••5678', PhoneIdentity::masked('+880 1711-005678'));
        $this->assertSame('', PhoneIdentity::masked(null));
        $this->assertSame('+8801711005678', PhoneIdentity::forDownlineViewer('+8801711005678', 9, 4, 4));
        $this->assertSame('••••5678', PhoneIdentity::forDownlineViewer('+8801711005678', 9, 5, 4));
        $this->assertSame('+8801711005678', PhoneIdentity::forDownlineViewer('+8801711005678', 4, null, 4));
    }
}
