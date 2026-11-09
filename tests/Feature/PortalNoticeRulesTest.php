<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who sees the banner at the top of a portal, and on which pages.
 */
class PortalNoticeRulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_starting_welcome_line_stays_on_the_dashboard(): void
    {
        foreach (['dealer', 'sub_dealer', 'finance'] as $role) {
            $user = User::factory()->create(['role' => $role, 'status' => 'active']);

            $this->assertFalse(DashboardManagement::noticeForUser($user)['every_page'], $role);
        }
    }

    public function test_a_notice_the_owner_wrote_shows_on_every_page(): void
    {
        DashboardManagement::save([
            'dealer_announcement' => 'Sales count closes on the 25th.',
            'subdealer_announcement' => 'Salary is checked on the last day.',
            'employee_announcement' => 'Check received stock before sending.',
        ]);

        $expected = ['dealer' => 'Sales count closes on the 25th.', 'sub_dealer' => 'Salary is checked on the last day.', 'support' => 'Check received stock before sending.'];
        foreach ($expected as $role => $text) {
            $notice = DashboardManagement::noticeForUser(User::factory()->create(['role' => $role, 'status' => 'active']));

            $this->assertSame($text, $notice['text'], $role);
            $this->assertTrue($notice['every_page'], $role);
        }
    }

    public function test_an_emptied_notice_goes_back_to_the_starting_line(): void
    {
        DashboardManagement::save(['dealer_announcement' => '']);
        app()->setLocale('en');

        $notice = DashboardManagement::noticeForUser(User::factory()->create(['role' => 'dealer', 'status' => 'active']));

        $this->assertSame(__('portal.dealer_announcement_default', [], 'en'), $notice['text']);
        $this->assertFalse($notice['every_page']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function officeRoles(): array
    {
        return collect(['employee', 'finance', 'inventory', 'account_manager', 'support', 'sr', 'editor', 'custom_field_team'])
            ->mapWithKeys(fn (string $role): array => [$role => [$role]])
            ->all();
    }

    #[DataProvider('officeRoles')]
    public function test_the_staff_notice_reaches_every_office_role(string $role): void
    {
        DashboardManagement::save(['employee_announcement' => 'Office closes at four on Thursday.']);

        $notice = DashboardManagement::noticeForUser(User::factory()->create(['role' => $role, 'status' => 'active']));

        $this->assertSame('Office closes at four on Thursday.', $notice['text']);
    }

    public function test_owners_have_no_staff_notice(): void
    {
        foreach (['super_admin', 'md', 'chairman'] as $role) {
            $this->assertNull(DashboardManagement::noticeForUser(User::factory()->create(['role' => $role, 'status' => 'active'])), $role);
        }
    }

    public function test_a_partner_in_a_linked_staff_workspace_reads_the_staff_notice(): void
    {
        DashboardManagement::save(['employee_announcement' => 'Staff only news.', 'dealer_announcement' => 'Dealer news.', 'dealer_maintenance_mode' => true]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'linked_staff_role' => 'employee']);

        $this->assertSame('Dealer news.', DashboardManagement::noticeForUser($dealer)['text']);

        session(['workspace_mode' => 'staff', 'workspace_user_id' => $dealer->id]);
        $notice = DashboardManagement::noticeForUser($dealer);

        $this->assertSame('Staff only news.', $notice['text']);
        $this->assertFalse($notice['maintenance']);
    }
}
