<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAgreement;
use App\Domain\Orders\StaffDashboardSummary;
use App\Domain\Support\PartnerChat;
use App\Models\PartnerConversation;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The agreement before the first order, a partner's change request, and what happens to unanswered chat. */
class PartnerContactImprovementsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_agreement_is_required_by_default_and_a_partner_is_sent_to_it(): void
    {
        Config::set('madina.agreement_required_default', true);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->assertTrue(app(PartnerAgreement::class)->setting()['required']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')->assertRedirect('/agreement');
    }

    public function test_an_owner_who_switched_the_agreement_off_keeps_it_off(): void
    {
        Config::set('madina.agreement_required_default', true);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(PartnerAgreement::class)->saveSetting($owner, false, 0);

        $this->assertFalse(app(PartnerAgreement::class)->setting()['required']);
    }

    public function test_a_partner_asks_the_office_to_change_a_locked_detail(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711111111']);
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/profile/change-request', ['phone' => '01722222222', 'note' => 'Lost my SIM'])
            ->assertSessionHasNoErrors();

        $this->assertSame('01711111111', $dealer->fresh()->phone);
        $this->assertDatabaseHas('audit_events', ['action' => 'profile.change_requested']);
        $this->assertTrue(DB::table('account_notices')->where('user_id', $staff->id)->where('body', 'like', '%01722222222%')->exists());
    }

    public function test_a_change_request_with_nothing_new_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01711111111']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/profile/change-request', ['phone' => '01711111111', 'note' => 'x'])
            ->assertSessionHasErrors('note');
    }

    public function test_an_unanswered_partner_message_is_counted_until_the_office_replies(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Please cancel my order']);
        $conversation = PartnerConversation::query()->where('partner_id', $dealer->id)->firstOrFail();
        $this->assertNotNull($conversation->awaiting_since);
        PartnerConversation::query()->whereKey($conversation->id)->update(['awaiting_since' => now()->subDays(3)]);

        $waiting = app(PartnerChat::class)->unanswered($owner);
        $this->assertSame(1, $waiting['count']);
        $this->assertSame(3, $waiting['oldest_days']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/partner-messages/'.$dealer->id, ['body' => 'Done'])->assertRedirect();
        $this->assertNull($conversation->fresh()->awaiting_since);
        $this->assertSame(0, app(PartnerChat::class)->unanswered($owner)['count']);
    }

    public function test_the_partner_sees_that_the_office_has_read_the_message(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Hello']);

        $chat = app(PartnerChat::class);
        $this->assertFalse($chat->partnerThread($dealer)['seen_by_office']);

        $chat->officeThread($owner, $dealer);
        $this->assertTrue($chat->partnerThread($dealer)['seen_by_office']);
        $this->assertSame(1, PartnerMessage::query()->count());
    }

    public function test_the_staff_dashboard_row_shows_how_long_the_oldest_message_has_waited(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Hello']);

        $queue = collect(app(StaffDashboardSummary::class)->for($owner)['work_queue']);
        $row = $queue->firstWhere('key', 'chats_unanswered');
        $this->assertNotNull($row);
        $this->assertSame(1, $row['count']);
    }
}
