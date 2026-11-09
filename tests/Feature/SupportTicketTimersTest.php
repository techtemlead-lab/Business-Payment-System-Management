<?php

namespace Tests\Feature;

use App\Domain\Support\SupportTickets;
use App\Models\User;
use App\Support\DashboardManagement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupportTicketTimersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_desk_shows_how_long_each_open_ticket_has_waited_and_marks_late_ones(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);
        $fresh = $this->ticket($dealer, 'Fresh', now()->subHours(3));
        $late = $this->ticket($dealer, 'Late', now()->subHours(30));
        $answered = $this->ticket($dealer, 'Answered', now()->subHours(50));
        $this->reply($answered, $staff, true, now()->subHours(40));
        $followUp = $this->ticket($dealer, 'Follow up', now()->subHours(80));
        $this->reply($followUp, $staff, true, now()->subHours(60));
        $this->reply($followUp, $dealer, false, now()->subHours(26));
        $this->reply($followUp, $dealer, false, now()->subHours(2));
        $closed = $this->ticket($dealer, 'Closed', now()->subHours(90), 'resolved');

        $desk = app(SupportTickets::class)->adminIndex();
        $byId = collect($desk['tickets'])->keyBy('id');

        $this->assertSame(3, $byId[$fresh]['waiting_hours']);
        $this->assertFalse($byId[$fresh]['overdue']);
        $this->assertSame(30, $byId[$late]['waiting_hours']);
        $this->assertTrue($byId[$late]['overdue']);
        $this->assertNull($byId[$answered]['waiting_hours'], 'The office had the last word, so the partner is not waiting on us.');
        $this->assertFalse($byId[$answered]['overdue']);
        $this->assertSame(26, $byId[$followUp]['waiting_hours'], 'The clock starts at the first partner message after our last reply.');
        $this->assertTrue($byId[$followUp]['overdue']);
        $this->assertNull($byId[$closed]['waiting_hours']);
        $this->assertSame(2, $desk['overdue_count']);
        $this->assertSame(24, $desk['overdue_hours']);

        $onlyLate = app(SupportTickets::class)->adminIndex(SupportTickets::OverdueFilter);
        $this->assertEqualsCanonicalizing([$late, $followUp], array_column($onlyLate['tickets'], 'id'));
    }

    public function test_owner_can_change_the_overdue_limit(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->ticket($dealer, 'Six hours', now()->subHours(6));
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->assertSame(24, DashboardManagement::defaults()['support_overdue_hours']);
        $this->assertSame(0, app(SupportTickets::class)->overdueCount());

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/dashboards', ['settings' => ['support_overdue_hours' => '4']])->assertRedirect();
        $this->assertSame(4, SupportTickets::overdueHours());
        $this->assertSame(1, app(SupportTickets::class)->overdueCount());

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/dashboards', ['settings' => ['support_overdue_hours' => '0']])
            ->assertSessionHasErrors('support_overdue_hours');
        $this->assertSame(4, SupportTickets::overdueHours());
    }

    public function test_support_desk_page_carries_the_timers(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->ticket($dealer, 'Late', now()->subHours(30));
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/support-admin?status=overdue')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.support_admin.overdue_count', 1)
                ->where('data.support_admin.table.rows.data.0.overdue', true)
                ->where('data.support_admin.table.rows.data.0.waiting_hours', 30));
    }

    public function test_daily_reminder_reaches_staff_who_answer_tickets_in_their_language(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->staff()->create();
        $accounts = User::factory()->create(['role' => 'account_manager', 'status' => 'active', 'locale' => 'bn']);
        $editor = User::factory()->staff('editor')->create();
        $this->ticket($dealer, 'Late one', now()->subHours(30));
        $this->ticket($dealer, 'Late two', now()->subHours(48));
        $this->ticket($dealer, 'Fresh', now()->subHours(1));

        $this->artisan('support:overdue-reminders')->assertSuccessful();

        foreach ([$owner, $accounts] as $user) {
            $this->assertSame(1, DB::table('account_notices')->where('user_id', $user->id)->count());
        }
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $editor->id)->count());
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $ownerNotice = DB::table('account_notices')->where('user_id', $owner->id)->first();
        $this->assertSame(__('portal.notice_support_overdue_title', [], 'en'), $ownerNotice->title);
        $this->assertStringContainsString('2 support tickets have waited more than 24 hours', $ownerNotice->body);
        $this->assertSame(__('portal.notice_support_overdue_title', [], 'bn'), DB::table('account_notices')->where('user_id', $accounts->id)->value('title'));
    }

    public function test_daily_reminder_sends_nothing_when_no_ticket_is_late(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        User::factory()->staff()->create();
        $this->ticket($dealer, 'Fresh', now()->subHours(5));

        $this->artisan('support:overdue-reminders')->assertSuccessful();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    private function ticket(User $partner, string $subject, Carbon $createdAt, string $status = 'pending'): string
    {
        $id = (string) Str::uuid();
        DB::table('support_tickets')->insert([
            'id' => $id, 'user_id' => $partner->id, 'category' => 'other', 'subject' => $subject, 'body' => 'Please help',
            'status' => $status, 'created_at' => $createdAt, 'updated_at' => $createdAt,
            'resolved_at' => $status === 'resolved' ? $createdAt : null,
        ]);

        return $id;
    }

    private function reply(string $ticketId, User $author, bool $staff, Carbon $createdAt): void
    {
        DB::table('support_ticket_replies')->insert([
            'support_ticket_id' => $ticketId, 'user_id' => $author->id, 'body' => 'Reply', 'is_staff' => $staff,
            'created_at' => $createdAt, 'updated_at' => $createdAt,
        ]);
    }
}
