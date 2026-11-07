<?php

namespace Tests\Feature;

use App\Domain\Content\PartnerEventNotifications;
use App\Jobs\SendPartnerEventSms;
use App\Models\PartnerEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PartnerEventCalendarTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        // 19:00 UTC on 9 November is already 01:00 on 10 November in Dhaka.
        $this->travelTo(Carbon::parse('2026-11-09 19:00:00', 'UTC'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function eventForm(array $overrides = []): array
    {
        return [
            'title_bn' => 'মাসিক ডিলার সভা',
            'title_en' => 'Monthly dealer meeting',
            'date' => '2026-11-12',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'place' => 'Pirganj office, Thana Road',
            'audience' => 'all',
            'notes' => 'Bring your ID card.',
            ...$overrides,
        ];
    }

    public function test_publishing_notifies_only_active_partners_in_the_chosen_divisions(): void
    {
        $this->seedClassicPlaceCodes();
        $editor = User::factory()->staff('editor')->create();
        $dhakaDealer = User::factory()->dealer()->create(['division' => 'Dhaka', 'locale' => 'en', 'phone' => '01711000001']);
        $dhakaSubDealer = User::factory()->subDealer()->create(['division' => 'Dhaka', 'locale' => 'bn', 'phone' => '01711000002']);
        $rangpurDealer = User::factory()->dealer()->create(['division' => 'Rangpur', 'phone' => '01711000003']);
        User::factory()->dealer()->suspended()->create(['division' => 'Dhaka']);
        User::factory()->staff('finance')->create(['division' => 'Dhaka']);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm([
            'audience' => 'divisions',
            'divisions' => ['Dhaka'],
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $event = PartnerEvent::query()->sole();
        $this->assertSame('draft', $event->status);
        $this->assertFalse($event->send_sms, 'SMS is off unless the box is ticked.');
        $this->assertSame(0, DB::table('account_notices')->count(), 'Saving a draft tells nobody.');

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events/'.$event->id.'/publish')
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('published', $event->fresh()->status);
        $this->assertEqualsCanonicalizing([$dhakaDealer->id, $dhakaSubDealer->id], DB::table('account_notices')->pluck('user_id')->all());
        $this->assertDatabaseHas('account_notices', ['user_id' => $dhakaDealer->id, 'title' => 'New meeting: Monthly dealer meeting']);
        $this->assertDatabaseHas('account_notices', ['user_id' => $dhakaSubDealer->id, 'title' => 'নতুন মিটিং: মাসিক ডিলার সভা']);
        $body = (string) DB::table('account_notices')->where('user_id', $dhakaDealer->id)->value('body');
        $this->assertStringContainsString('12 November 2026 at 10:00 AM', $body);
        $this->assertStringContainsString('Pirganj office', $body);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $rangpurDealer->id]);
        $this->assertSame([], config('madina.sms.sent'), 'No SMS when the event has SMS switched off.');

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events/'.$event->id.'/publish')
            ->assertSessionHasErrors('status');
        $this->assertSame(2, DB::table('account_notices')->count(), 'Publishing twice does not notify twice.');
    }

    public function test_sms_goes_out_only_when_the_event_box_is_ticked(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01711000010', 'locale' => 'en']);
        $subDealer = User::factory()->subDealer()->create(['phone' => '01811000011', 'locale' => 'bn']);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm([
            'send_sms' => true,
            'publish' => true,
        ]))->assertRedirect()->assertSessionHasNoErrors();

        $sent = collect(config('madina.sms.sent'));
        $this->assertSame([$dealer->id, $subDealer->id], $sent->pluck('user_id')->sort()->values()->all());
        $this->assertSame(['event.published'], $sent->pluck('event')->unique()->values()->all());
        $this->assertStringContainsString('Monthly dealer meeting', (string) $sent->firstWhere('user_id', $dealer->id)['message']);
        $this->assertSame(2, DB::table('account_notices')->count());
    }

    public function test_cancelling_a_published_event_tells_its_audience_and_a_draft_tells_nobody(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $dealer = User::factory()->dealer()->create(['locale' => 'en']);
        $subDealer = User::factory()->subDealer()->create(['locale' => 'bn']);
        $published = PartnerEvent::factory()->published()->startingAt('2026-11-15 15:00')->create(['title_en' => 'Product training', 'title_bn' => 'পণ্য প্রশিক্ষণ']);
        $draft = PartnerEvent::factory()->startingAt('2026-11-16 15:00')->create();

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events/'.$draft->id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $draft->fresh()->status);
        $this->assertSame(0, DB::table('account_notices')->count());

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events/'.$published->id.'/cancel', ['reason' => 'Heavy rain'])
            ->assertRedirect()->assertSessionHas('success');

        $this->assertSame('cancelled', $published->fresh()->status);
        $this->assertNotNull($published->fresh()->cancelled_at);
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $dealer->id,
            'title' => 'Meeting cancelled: Product training',
            'body' => 'The meeting "Product training" on 15 November 2026 at 3:00 PM has been cancelled. Reason: Heavy rain',
        ]);
        $this->assertDatabaseHas('account_notices', ['user_id' => $subDealer->id, 'title' => 'মিটিং বাতিল: পণ্য প্রশিক্ষণ']);
        $this->assertSame(2, DB::table('account_notices')->count());
        $this->assertSame([], config('madina.sms.sent'));

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events/'.$published->id.'/cancel')->assertSessionHasErrors('status');
        $this->actingAs($editor)->onPortal('manage')->patch('/commands/events/'.$published->id, $this->eventForm())->assertSessionHasErrors('status');
        $this->assertSame(2, DB::table('account_notices')->count());
    }

    public function test_partners_only_see_published_events_for_everyone_or_their_division(): void
    {
        $forAll = PartnerEvent::factory()->published()->startingAt('2026-11-20 10:00')->create(['title_en' => 'Everyone']);
        $forDhaka = PartnerEvent::factory()->published()->startingAt('2026-11-21 10:00')->forDivisions(['Dhaka'])->create(['title_en' => 'Dhaka only']);
        $forRangpur = PartnerEvent::factory()->published()->startingAt('2026-11-22 10:00')->forDivisions(['Rangpur', 'Khulna'])->create(['title_en' => 'Rangpur and Khulna']);
        PartnerEvent::factory()->startingAt('2026-11-23 10:00')->create(['title_en' => 'Draft']);
        PartnerEvent::factory()->startingAt('2026-11-24 10:00')->create(['title_en' => 'Cancelled draft', 'status' => 'cancelled']);
        $cancelled = PartnerEvent::factory()->published()->startingAt('2026-11-25 10:00')->create([
            'title_en' => 'Called off', 'status' => 'cancelled', 'online_url' => 'https://meet.google.com/abc-defg-hij',
        ]);

        $dhakaDealer = User::factory()->dealer()->create(['division' => 'Dhaka']);
        $titles = fn (array $months): array => collect($months)->pluck('days')->flatten(1)->pluck('events')->flatten(1)->pluck('title_en')->all();

        $this->actingAs($dhakaDealer)->onPortal('dealer')->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'events')
                ->where('data.events_calendar.mode', 'partner')
                ->where('data.events_calendar.months', fn ($months) => $titles(collect($months)->toArray()) === ['Everyone', 'Dhaka only', 'Called off'])
                ->where('data.events_calendar.months.0.days.2.events.0.status', 'cancelled')
                ->where('data.events_calendar.months.0.days.2.events.0.online_url', null)
                ->missing('data.events_calendar.months.0.days.0.events.0.divisions'));

        $rangpurSubDealer = User::factory()->subDealer()->create(['division' => 'Rangpur']);
        $this->actingAs($rangpurSubDealer)->onPortal('subdealer')->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/subdealer/Dashboard')
                ->where('data.events_calendar.months', fn ($months) => $titles(collect($months)->toArray()) === ['Everyone', 'Rangpur and Khulna', 'Called off']));

        $noDivision = User::factory()->subDealer()->create(['division' => null]);
        $this->assertTrue($forAll->isVisibleTo($noDivision));
        $this->assertFalse($forDhaka->isVisibleTo($noDivision));
        $this->assertSame(2, PartnerEvent::query()->visibleTo($noDivision)->count());
        $this->assertFalse($forRangpur->isVisibleTo($dhakaDealer));
        $this->assertTrue($cancelled->isVisibleTo($dhakaDealer));
    }

    public function test_dates_and_times_are_entered_and_shown_in_dhaka_time(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm([
            'title_en' => 'Morning training',
            'date' => '2026-11-10',
            'start_time' => '09:30',
            'end_time' => '11:00',
            'publish' => true,
        ]))->assertSessionHasNoErrors();

        $event = PartnerEvent::query()->sole();
        $this->assertSame('2026-11-10 03:30:00', $event->starts_at->utc()->format('Y-m-d H:i:s'), '09:30 in Dhaka is 03:30 UTC.');
        $this->assertSame('2026-11-10 05:00:00', $event->ends_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-10 03:30:00', DB::table('partner_events')->value('starts_at'));

        // 23:00 Dhaka on 9 November (17:00 UTC) already ended; "today" in Dhaka is 10 November.
        PartnerEvent::factory()->published()->startingAt('2026-11-09 23:00', 1)->create(['title_en' => 'Last night']);
        PartnerEvent::factory()->published()->startingAt('2026-11-14 10:00')->create(['title_en' => 'Saturday']);
        PartnerEvent::factory()->published()->startingAt('2026-11-17 10:00')->create(['title_en' => 'Next Tuesday']);
        PartnerEvent::factory()->published()->startingAt('2026-12-02 16:00')->create(['title_en' => 'December']);

        $dealer = User::factory()->dealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.events_calendar.today_date', '2026-11-10')
                ->where('data.events_calendar.timezone', 'Asia/Dhaka')
                ->has('data.events_calendar.today', 1)
                ->where('data.events_calendar.today.0.title_en', 'Morning training')
                ->where('data.events_calendar.today.0.date', '2026-11-10')
                ->where('data.events_calendar.today.0.start_time', '09:30')
                ->where('data.events_calendar.today.0.end_time', '11:00')
                ->where('data.events_calendar.today.0.starts_at', '2026-11-10T09:30:00+06:00')
                ->has('data.events_calendar.this_week', 1)
                ->where('data.events_calendar.this_week.0.title_en', 'Saturday')
                ->where('data.events_calendar.months.0.month', '2026-11')
                ->where('data.events_calendar.months.0.days.0.date', '2026-11-10')
                ->where('data.events_calendar.months.1.month', '2026-12')
                ->where('data.events_calendar.months.1.days.0.events.0.start_time', '16:00')
                ->has('data.events_calendar.past', 1)
                ->where('data.events_calendar.past.0.title_en', 'Last night')
                ->where('data.events_calendar.past.0.date', '2026-11-09'));

        $this->travelTo(Carbon::parse('2026-11-10 04:00:00', 'UTC'));
        $this->actingAs($dealer)->onPortal('dealer')->get('/events')
            ->assertInertia(fn (Assert $page) => $page->where('data.events_calendar.today.0.is_ongoing', true));
    }

    public function test_times_and_links_are_checked(): void
    {
        $editor = User::factory()->staff('editor')->create();

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['start_time' => '15:00', 'end_time' => '14:00']))
            ->assertSessionHasErrors('end_time');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['date' => '2026-11-09']))
            ->assertSessionHasErrors('date');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['start_time' => '25:00']))
            ->assertSessionHasErrors('start_time');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['place' => '', 'online_url' => '']))
            ->assertSessionHasErrors('place');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['place' => '', 'online_url' => 'http://zoom.us/j/123']))
            ->assertSessionHasErrors('online_url');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['audience' => 'divisions', 'divisions' => []]))
            ->assertSessionHasErrors('divisions');
        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['audience' => 'divisions', 'divisions' => ['Atlantis']]))
            ->assertSessionHasErrors('divisions.0');
        $this->assertDatabaseCount('partner_events', 0);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['place' => '', 'online_url' => 'https://zoom.us/j/123']))
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('partner_events', ['online_url' => 'https://zoom.us/j/123', 'place' => null]);
    }

    public function test_only_content_staff_and_owners_can_manage_events(): void
    {
        $event = PartnerEvent::factory()->startingAt('2026-11-20 10:00')->create();

        foreach (['finance', 'inventory', 'account_manager'] as $role) {
            $staff = User::factory()->staff($role)->create();
            $this->actingAs($staff)->onPortal('manage')->post('/commands/events', $this->eventForm())->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->patch('/commands/events/'.$event->id, $this->eventForm())->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->post('/commands/events/'.$event->id.'/publish')->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->post('/commands/events/'.$event->id.'/cancel')->assertForbidden();
            $this->actingAs($staff)->onPortal('manage')->get('/events')->assertForbidden();
        }

        $dealer = User::factory()->dealer()->create();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/events', $this->eventForm())->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/events/'.$event->id.'/publish')->assertForbidden();
        $subDealer = User::factory()->subDealer()->create();
        $this->actingAs($subDealer)->onPortal('subdealer')->post('/commands/events/'.$event->id.'/cancel')->assertForbidden();
        $this->assertSame('draft', $event->fresh()->status);
        $this->assertSame(0, DB::table('account_notices')->count());

        $chairman = User::factory()->staff('chairman')->create();
        $this->actingAs($chairman)->onPortal('sadmin')->get('/events')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('data.events_calendar.mode', 'staff')->has('data.events_calendar.events', 1));
        $this->actingAs($chairman)->onPortal('sadmin')->post('/commands/events/'.$event->id.'/publish')->assertSessionHasNoErrors();
        $this->assertSame('published', $event->fresh()->status);

        $editor = User::factory()->staff('editor')->create();
        $draft = PartnerEvent::factory()->startingAt('2026-11-21 10:00')->create();
        $this->actingAs($editor)->onPortal('manage')->delete('/commands/events/'.$event->id)->assertSessionHasErrors('status');
        $this->actingAs($editor)->onPortal('manage')->delete('/commands/events/'.$draft->id)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('partner_events', ['id' => $draft->id]);
    }

    public function test_event_sms_is_queued_after_saving_instead_of_sent_inside_the_request(): void
    {
        Queue::fake();
        $editor = User::factory()->staff('editor')->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01711000020', 'locale' => 'en']);
        $subDealer = User::factory()->subDealer()->create(['phone' => '01811000021']);

        $this->actingAs($editor)->onPortal('manage')->post('/commands/events', $this->eventForm(['send_sms' => true, 'publish' => true]))
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], config('madina.sms.sent'), 'No SMS is sent while the office screen waits.');
        $this->assertSame(2, DB::table('account_notices')->count(), 'Portal notices are still written straight away.');
        $event = PartnerEvent::query()->sole();
        Queue::assertPushed(SendPartnerEventSms::class, fn (SendPartnerEventSms $job): bool => $job->eventId === $event->id
            && $job->smsEvent === 'event.published'
            && collect($job->userIds)->sort()->values()->all() === [$dealer->id, $subDealer->id]);

        Queue::pushedJobs()[SendPartnerEventSms::class][0]['job']->handle(app(PartnerEventNotifications::class));
        $this->assertSame([$dealer->id, $subDealer->id], collect(config('madina.sms.sent'))->pluck('user_id')->sort()->values()->all());
    }

    public function test_a_queued_published_text_is_dropped_when_the_meeting_was_cancelled_first(): void
    {
        $dealer = User::factory()->dealer()->create(['phone' => '01711000030']);
        $event = PartnerEvent::factory()->create(['status' => 'cancelled', 'send_sms' => true]);

        (new SendPartnerEventSms($event->id, [$dealer->id], 'event.published', 'portal.events_sms_published'))->handle(app(PartnerEventNotifications::class));

        $this->assertSame([], config('madina.sms.sent'));
    }
}
