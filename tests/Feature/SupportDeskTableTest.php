<?php

namespace Tests\Feature;

use App\Domain\Pricing\ProductPricing;
use App\Domain\Support\SupportTickets;
use App\Http\Portals\Shared\Sections\SectionContext;
use App\Http\Portals\Shared\Sections\SupportSection;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SupportDeskTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_each_ticket_is_one_row_with_its_replies_and_the_state_counts(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'name' => 'Rahim Dealer']);
        $staff = User::factory()->create(['role' => 'account_manager', 'status' => 'active', 'name' => 'Desk Person']);
        $answered = $this->ticket($dealer, 'Answered', now()->subHours(50));
        $this->reply($answered, $staff, true, now()->subHours(40), 'We are on it');
        $this->ticket($dealer, 'Fresh', now()->subHours(3));
        $this->ticket($dealer, 'Closed', now()->subHours(90), 'resolved');

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/support-admin')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.support_admin.table.totals.count', 3)
                ->where('data.support_admin.table.rows.data.0.subject', 'Fresh')
                ->where('data.support_admin.table.rows.data.0.user_name', 'Rahim Dealer')
                ->where('data.support_admin.table.rows.data.1.subject', 'Answered')
                ->where('data.support_admin.table.rows.data.1.replies.0.author_name', 'Desk Person')
                ->where('data.support_admin.table.rows.data.1.replies.0.body', 'We are on it')
                ->where('data.support_admin.counts.pending', 2)
                ->where('data.support_admin.counts.resolved', 1)
                ->where('data.support_admin.overdue_count', 0));
    }

    public function test_search_filters_and_the_date_view_narrow_the_list(): void
    {
        $rahim = User::factory()->dealer()->create(['status' => 'active', 'name' => 'Rahim Dealer']);
        $karim = User::factory()->subDealer()->create(['status' => 'active', 'name' => 'Karim Partner']);
        $this->ticket($rahim, 'Invoice will not print', now()->subHours(3));
        $this->ticket($karim, 'Commission looks short', now()->subHours(5), category: 'commission');
        $this->ticket($karim, 'Older question', now()->subDays(40));

        $this->assertSame(['Invoice will not print'], $this->subjects('/support-admin?q=rahim'));
        $this->assertSame(['Commission looks short'], $this->subjects('/support-admin?q=short'));
        $this->assertSame(['Commission looks short'], $this->subjects('/support-admin?category=commission'));
        $this->assertSame(['Invoice will not print', 'Commission looks short'], $this->subjects('/support-admin?period=week'));
        $this->assertSame(['Invoice will not print', 'Commission looks short', 'Older question'], $this->subjects('/support-admin?period=&q='));
    }

    public function test_the_list_and_the_late_count_agree_about_who_is_waiting(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);
        $late = $this->ticket($dealer, 'Late', now()->subHours(30));
        $fresh = $this->ticket($dealer, 'Fresh', now()->subHours(3));
        $answered = $this->ticket($dealer, 'Answered', now()->subHours(50));
        $this->reply($answered, $staff, true, now()->subHours(40));
        $followUp = $this->ticket($dealer, 'Follow up', now()->subHours(80));
        $this->reply($followUp, $staff, true, now()->subHours(60));
        $this->reply($followUp, $dealer, false, now()->subHours(26));
        $this->ticket($dealer, 'Closed', now()->subHours(90), 'resolved');
        $tickets = app(SupportTickets::class);

        $rows = collect($this->rows('/support-admin'))->keyBy('id');
        foreach ($tickets->adminIndex()['tickets'] as $ticket) {
            $this->assertSame($ticket['waiting_hours'], $rows[$ticket['id']]['waiting_hours'], $ticket['subject']);
            $this->assertSame($ticket['overdue'], $rows[$ticket['id']]['overdue'], $ticket['subject']);
        }

        $this->assertSame(30, $rows[$late]['waiting_hours']);
        $this->assertSame(3, $rows[$fresh]['waiting_hours']);
        $this->assertNull($rows[$answered]['waiting_hours']);
        $this->assertSame(26, $rows[$followUp]['waiting_hours']);
        $this->assertEqualsCanonicalizing([$late, $followUp], array_column($this->rows('/support-admin?status=overdue'), 'id'));
        $this->assertSame($tickets->overdueCount(), count($this->rows('/support-admin?status=overdue')));
    }

    public function test_an_unknown_status_is_ignored_and_every_status_filters(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->ticket($dealer, 'Waiting', now()->subHours(3));
        $this->ticket($dealer, 'Working', now()->subHours(4), 'in_progress');

        $this->assertSame(['Working'], $this->subjects('/support-admin?status=in_progress'));
        $this->assertSame(['Waiting', 'Working'], $this->subjects('/support-admin?status=nonsense'));
    }

    public function test_the_list_sorts_across_every_ticket_and_longest_wait_can_come_first(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);
        $this->ticket($dealer, 'Newer', now()->subHours(2));
        $this->ticket($dealer, 'Oldest', now()->subHours(40));
        $answered = $this->ticket($dealer, 'Answered', now()->subHours(90));
        $this->reply($answered, $staff, true, now()->subHours(80));

        $this->assertSame(['Oldest', 'Newer', 'Answered'], array_column($this->sectionRows(['sort' => 'waiting', 'dir' => 'asc']), 'subject'), 'Tickets nobody is waiting on go last.');
        $this->assertSame(['Answered', 'Newer', 'Oldest'], array_column($this->sectionRows(['sort' => 'subject', 'dir' => 'asc']), 'subject'));
    }

    public function test_the_list_goes_past_the_old_limit_of_fifty_tickets(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        foreach (range(1, 52) as $number) {
            $this->ticket($dealer, 'Ticket '.$number, now()->subMinutes($number));
        }

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/support-admin?page=3')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.support_admin.table.totals.count', 52)
                ->has('data.support_admin.table.rows.data', 2)
                ->where('data.support_admin.table.rows.data.1.subject', 'Ticket 52'));
    }

    public function test_a_partner_cannot_open_the_support_desk(): void
    {
        $this->actingAs(User::factory()->dealer()->create(['status' => 'active']))
            ->onPortal('dealer')
            ->get('/support-admin')
            ->assertNotFound();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $url): array
    {
        $rows = [];
        $this->actingAs($this->owner())->onPortal('sadmin')->get($url)
            ->assertOk()
            ->assertInertia(function (Assert $page) use (&$rows): void {
                $rows = $page->toArray()['props']['data']['support_admin']['table']['rows']['data'];
            });

        return $rows;
    }

    /**
     * @return list<string>
     */
    private function subjects(string $url): array
    {
        return array_column($this->rows($url), 'subject');
    }

    /**
     * Reads the section directly, for the column sort the workspace page does not let through yet.
     *
     * @param  array<string, string>  $query
     * @return list<array<string, mixed>>
     */
    private function sectionRows(array $query): array
    {
        $owner = $this->owner();
        $context = new SectionContext(
            request: Request::create('/support-admin', 'GET', $query),
            user: $owner,
            portal: 'sadmin',
            section: 'support-admin',
            isStaff: true,
            usingStaffWorkspace: false,
            catalogPortal: 'sadmin',
            orders: Order::query(),
            pricing: app(ProductPricing::class),
        );

        return app(SupportSection::class)->data($context, [])['support_admin']['table']['rows']->items();
    }

    private function ticket(User $partner, string $subject, Carbon $at, string $status = 'pending', string $category = 'technical'): string
    {
        $id = (string) Str::uuid();
        DB::table('support_tickets')->insert([
            'id' => $id,
            'user_id' => $partner->id,
            'category' => $category,
            'subject' => $subject,
            'body' => $subject.' body',
            'status' => $status,
            'created_at' => $at,
            'updated_at' => $at,
        ]);

        return $id;
    }

    private function reply(string $ticketId, User $author, bool $isStaff, Carbon $at, string $body = 'A reply'): void
    {
        DB::table('support_ticket_replies')->insert([
            'support_ticket_id' => $ticketId,
            'user_id' => $author->id,
            'body' => $body,
            'is_staff' => $isStaff,
            'created_at' => $at,
            'updated_at' => $at,
        ]);
    }
}
