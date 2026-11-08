<?php

namespace Tests\Feature;

use App\Domain\Clinic\PrescriberVerification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Integrations\SmtpMailConfig;
use App\Domain\Notifications\EmailEventSwitches;
use App\Domain\Notifications\PartnerEventEmail;
use App\Domain\Orders\OrderService;
use App\Domain\Support\SupportTickets;
use App\Jobs\SendEventEmail;
use App\Listeners\NoticeNewStaffDevice;
use App\Mail\EventNoticeMail;
use App\Models\Product;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\TestCase;

/**
 * Mail is always faked: no email leaves these tests. The addresses only look real so
 * that the "is this a real address" check lets them through.
 */
class EventEmailTest extends TestCase
{
    use RefreshDatabase;

    private const Address = 'rahima.partner@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->enableFinancialPosting();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => [], 'madina.full_payment_when_ordering' => false]);
    }

    private function useRealMail(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'email-smtp.ap-southeast-1.amazonaws.com',
            'mail.from.address' => 'no-reply@madinanhc.com',
            'mail.from.name' => 'Madina Natural Health Care',
        ]);
    }

    /** @param  array<string, mixed>  $attributes */
    private function partner(array $attributes = []): User
    {
        return User::factory()->create(['role' => 'dealer', 'status' => 'active', 'phone' => '01720000091', 'email' => self::Address, 'locale' => 'en', ...$attributes]);
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    private function assertEmailedAbout(string $event, User $to, ?string $subjectPart = null): void
    {
        Mail::assertSent(EventNoticeMail::class, function (EventNoticeMail $mail) use ($to, $subjectPart): bool {
            return $mail->hasTo((string) $to->email) && ($subjectPart === null || str_contains($mail->noticeTitle.' '.$mail->noticeBody, $subjectPart));
        });
        $this->assertSame([], DB::table('email_delivery_failures')->pluck('reason')->all(), 'No failure for '.$event);
    }

    public function test_an_event_is_queued_not_sent_from_the_action(): void
    {
        $this->useRealMail();
        Queue::fake();
        Mail::fake();
        $partner = $this->partner();

        app(PartnerEventEmail::class)->send($partner, 'order.submitted', 'Order received', 'We got your order.');

        Queue::assertPushed(SendEventEmail::class, fn (SendEventEmail $job): bool => $job->userId === $partner->id && $job->event === 'order.submitted');
        Mail::assertNothingSent();
    }

    public function test_the_email_goes_in_the_persons_own_language(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner(['locale' => 'bn', 'name' => 'রহিমা']);

        app(PartnerEventEmail::class)->sendTranslated($partner, 'prescriber.enabled', 'event_emails.prescriber_enabled_title', 'event_emails.prescriber_enabled_body');

        Mail::assertSent(EventNoticeMail::class, function (EventNoticeMail $mail): bool {
            return $mail->locale === 'bn' && str_contains($mail->noticeTitle, 'প্রেসক্রিপশন');
        });
    }

    public function test_the_shared_template_shows_the_logo_and_escapes_the_text(): void
    {
        $mail = new EventNoticeMail('অর্ডার পাওয়া গেছে', 'আপনার অর্ডার <b>জমা</b> হয়েছে', 'রহিমা', 'https://dealer.madinanhc.com/', 'bn');
        config(['mail.from.name' => 'Madina Natural Health Care']);

        $mail->assertSeeInHtml('অর্ডার পাওয়া গেছে');
        $mail->assertSeeInHtml('প্রিয় রহিমা');
        $mail->assertSeeInHtml('data:image/png;base64,', false);
        $mail->assertSeeInHtml('আমার অ্যাকাউন্ট খুলুন');
        $mail->assertDontSeeInHtml('<b>জমা</b>', false);
        $mail->assertSeeInText('আপনার অর্ডার');
        $mail->assertSeeInText('https://dealer.madinanhc.com/');
    }

    public function test_the_log_transport_and_other_fake_setups_send_nothing(): void
    {
        Queue::fake();
        Mail::fake();
        $partner = $this->partner();
        foreach ([
            ['mail.default' => 'log'],
            ['mail.default' => 'array'],
            ['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '', 'mail.from.address' => 'no-reply@madinanhc.com'],
            ['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'mailpit', 'mail.from.address' => 'no-reply@madinanhc.com'],
            ['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'email-smtp.ap-southeast-1.amazonaws.com', 'mail.from.address' => 'hello@example.com'],
        ] as $setup) {
            config($setup);

            app(PartnerEventEmail::class)->send($partner, 'order.submitted', 'Title', 'Body');
        }

        Queue::assertNothingPushed();
        Mail::assertNothingSent();
    }

    public function test_only_real_addresses_get_email(): void
    {
        foreach (['', 'not-an-email', 'dealer@madina.test', 'removed-7@privacy.invalid', 'a@example.com', 'a@example.org', 'x@host.local'] as $address) {
            $this->assertFalse(PartnerEventEmail::isRealAddress($address), $address);
        }
        $this->assertTrue(PartnerEventEmail::isRealAddress('someone@gmail.com'));

        $this->useRealMail();
        Queue::fake();
        $placeholder = $this->partner(['email' => 'removed-7@privacy.invalid']);
        app(PartnerEventEmail::class)->send($placeholder, 'order.submitted', 'Title', 'Body');
        Queue::assertNothingPushed();
    }

    public function test_every_switch_starts_on_and_an_event_the_owner_turns_off_is_not_sent(): void
    {
        $this->useRealMail();
        $switches = app(EmailEventSwitches::class);
        foreach (EmailEventSwitches::Events as $event) {
            $this->assertTrue($switches->isOn($event), $event);
        }
        $this->assertFalse($switches->isOn('order.held'));

        $owner = $this->owner();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/integrations/email-events', [
            'enabled' => array_values(array_diff(EmailEventSwitches::Events, ['order.submitted'])),
            'version' => 0,
            'reason' => 'Too many order emails for now.',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['action' => 'integrations.email_events_saved', 'actor_id' => $owner->id]);

        Queue::fake();
        $partner = $this->partner();
        app(PartnerEventEmail::class)->send($partner, 'order.submitted', 'Title', 'Body');
        Queue::assertNothingPushed();
        app(PartnerEventEmail::class)->send($partner, 'order.approved', 'Title', 'Body');
        Queue::assertPushed(SendEventEmail::class, 1);
    }

    public function test_switching_an_event_off_after_it_was_queued_stops_it(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        DB::table('business_settings')->insert(['key' => EmailEventSwitches::SettingsKey, 'value' => json_encode(['off' => ['order.submitted']]), 'version' => 1, 'updated_by' => $this->owner()->id, 'created_at' => now(), 'updated_at' => now()]);

        (new SendEventEmail($partner->id, 'order.submitted', 'Title', 'Body'))->handle(app(SmtpMailConfig::class), app(EmailEventSwitches::class));

        Mail::assertNothingSent();
    }

    public function test_only_the_owner_can_change_the_switches_and_bad_input_is_refused(): void
    {
        $url = '/commands/operations/integrations/email-events';
        $staff = User::factory()->staff('finance')->create();
        $this->actingAs($staff)->onPortal('manage')->post($url, ['enabled' => [], 'version' => 0, 'reason' => 'Trying to change it'])->assertForbidden();

        $owner = $this->owner();
        $this->actingAs($owner)->onPortal('sadmin')->post($url, ['enabled' => ['not.an.event'], 'version' => 0, 'reason' => 'Trying this'])->assertSessionHasErrors('enabled.0');
        $this->actingAs($owner)->onPortal('sadmin')->post($url, ['enabled' => [], 'version' => 0])->assertSessionHasErrors('reason');
        $this->actingAs($owner)->onPortal('sadmin')->post($url, ['enabled' => [], 'version' => 9, 'reason' => 'Out of date form'])->assertSessionHasErrors('version');
        $this->assertDatabaseMissing('business_settings', ['key' => EmailEventSwitches::SettingsKey]);
    }

    public function test_the_page_gives_the_switches_and_the_failures_to_the_screen(): void
    {
        DB::table('email_delivery_failures')->insert(['event' => 'order.submitted', 'recipient_masked' => 'ra•••@gmail.com', 'reason' => '550 refused', 'created_at' => now()]);

        $panel = app(EmailEventSwitches::class)->panel();

        $this->assertFalse($panel['mail_ready']);
        $this->assertCount(count(EmailEventSwitches::Events), $panel['events']);
        $this->assertTrue($panel['events'][0]['enabled']);
        $this->assertSame('ra•••@gmail.com', $panel['failures'][0]['recipient']);
        $this->assertSame(__('event_emails.label_order_submitted'), $panel['failures'][0]['event']);
        foreach (EmailEventSwitches::Events as $event) {
            $key = 'label_'.str_replace('.', '_', $event);
            foreach (['en', 'bn'] as $locale) {
                $this->assertNotSame('event_emails.'.$key, __('event_emails.'.$key, [], $locale), $key);
            }
        }
    }

    public function test_a_mail_failure_never_stops_the_action_and_staff_can_see_it(): void
    {
        $this->useRealMail();
        Mail::shouldReceive('purge')->andReturnNull();
        Mail::shouldReceive('to')->andThrow(new TransportException('550 mailbox unavailable for '.self::Address));
        $partner = $this->partner();
        $reviewer = User::factory()->staff('finance')->create();
        $id = $this->withdrawal($partner, 'requested');

        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/withdrawals/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked the destination'])->assertRedirect();

        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'status' => 'approved']);
        $failure = DB::table('email_delivery_failures')->sole();
        $this->assertSame('withdrawal.approved', $failure->event);
        $this->assertSame('ra•••@gmail.com', $failure->recipient_masked);
        $this->assertStringNotContainsString(self::Address, (string) $failure->reason);
        $this->assertStringContainsString('550', (string) $failure->reason);
    }

    public function test_on_a_real_queue_a_failure_is_retried_before_it_is_recorded(): void
    {
        $this->useRealMail();
        Mail::shouldReceive('purge')->andReturnNull();
        Mail::shouldReceive('to')->andThrow(new TransportException('451 try later'));
        $partner = $this->partner();

        $firstTry = (new SendEventEmail($partner->id, 'order.submitted', 'Title', 'Body'))->withFakeQueueInteractions();
        $firstTry->handle(app(SmtpMailConfig::class), app(EmailEventSwitches::class));
        $firstTry->assertReleased();
        $this->assertDatabaseCount('email_delivery_failures', 0);

        $lastTry = (new SendEventEmail($partner->id, 'order.submitted', 'Title', 'Body'))->withFakeQueueInteractions();
        $lastTry->job->attempts = 3;
        $lastTry->handle(app(SmtpMailConfig::class), app(EmailEventSwitches::class));
        $lastTry->assertNotReleased();
        $this->assertDatabaseCount('email_delivery_failures', 1);
    }

    public function test_a_broken_queue_cannot_stop_the_caller(): void
    {
        $this->useRealMail();
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$logged): void {
            $logged[] = $log->message;
        });
        Bus::shouldReceive('dispatch')->andThrow(new RuntimeException('queue table missing'));

        app(PartnerEventEmail::class)->send($this->partner(), 'order.submitted', 'Title', 'Body');

        $this->assertContains('email.not_queued', $logged);
    }

    public function test_a_rolled_back_action_sends_nothing(): void
    {
        $this->useRealMail();
        Queue::fake();
        $partner = $this->partner();

        try {
            DB::transaction(function () use ($partner): void {
                app(PartnerEventEmail::class)->send($partner, 'order.submitted', 'Title', 'Body');
                throw new RuntimeException('the action failed');
            });
        } catch (RuntimeException) {
            // Expected: the action was rolled back.
        }

        Queue::assertNothingPushed();
    }

    public function test_order_placed_decided_paid_dispatched_and_delivered_each_send_an_email(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        $admin = $this->owner();
        $product = Product::create(['sku' => 'EM-'.Str::random(6), 'name' => 'Email Product', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20]);
        $orders = app(OrderService::class);

        $order = $orders->create($partner, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $this->assertEmailedAbout('order.submitted', $partner, $order->number);
        Mail::assertSent(EventNoticeMail::class, 1);

        $claimId = DB::table('payment_claims')->insertGetId(['order_id' => $order->id, 'channel' => 'bkash', 'reference' => 'REF-EMAIL-1', 'amount' => $order->total, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $orders->verify($admin, $claimId, true, 'Money received');
        Mail::assertSent(EventNoticeMail::class, 2);

        $orders->decision($admin, $order->fresh(), 'approved', 'Paid in full');
        Mail::assertSent(EventNoticeMail::class, 3);

        $rejected = $orders->create($this->partner(['email' => 'second.partner@gmail.com', 'phone' => '01720000092']), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $orders->decision($admin, $rejected, 'rejected', 'Wrong item');
        $this->assertEmailedAbout('order.rejected', User::query()->findOrFail($rejected->user_id));
    }

    public function test_delivery_confirmation_sends_an_email(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        $staff = $this->owner();
        $order = app(OrderService::class)->create($partner, ['items' => [['product_id' => Product::create(['sku' => 'EM-'.Str::random(6), 'name' => 'Delivered', 'category' => 'Test', 'mrp' => '200.00', 'dealer_price' => '140.00', 'stock' => 20])->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        Mail::fake();
        DB::table('orders')->where('id', $order->id)->update(['status' => 'approved', 'paid' => $order->total]);
        $shipment = DB::table('shipments')->insertGetId(['order_id' => $order->id, 'courier' => 'Test', 'tracking' => 'T1', 'lines' => '[]', 'actor_id' => $staff->id, 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => 'x', 'status' => 'dispatched', 'created_at' => now(), 'updated_at' => now()]);

        app(OrderService::class)->deliver($staff, $order->fresh(), $shipment, 'Handed over');

        $this->assertEmailedAbout('shipment.delivered', $partner, $order->number);
    }

    public function test_withdrawal_steps_each_send_an_email(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        $reviewer = User::factory()->staff('finance')->create();
        $payer = User::factory()->staff('finance')->create();

        $refused = $this->withdrawal($partner, 'requested');
        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/withdrawals/'.$refused.'/review', ['decision' => 'rejected', 'reason' => 'Could not verify it'])->assertRedirect();
        Mail::assertSent(EventNoticeMail::class, 1);

        DB::table('wallets')->where('user_id', $partner->id)->update(['held' => '200.00']);
        $paid = $this->withdrawal($partner, 'approved', $reviewer->id);
        $this->actingAs($payer)->onPortal('manage')->post('/commands/withdrawals/'.$paid.'/record-payment', [
            'method' => 'other', 'payment_reference' => 'PAY-EMAIL-1', 'paid_at' => now()->subHour()->format('Y-m-d H:i:s'),
            'note' => 'Sent by hand', 'receipt' => UploadedFile::fake()->create('proof.pdf', 20, 'application/pdf'),
        ])->assertRedirect();
        Mail::assertSent(EventNoticeMail::class, 2);
    }

    private function withdrawal(User $owner, string $status, ?int $reviewerId = null): string
    {
        $id = (string) Str::uuid();
        if (! DB::table('wallets')->where('user_id', $owner->id)->exists()) {
            DB::table('wallets')->insert(['user_id' => $owner->id, 'available' => '0.00', 'held' => '200.00', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('withdrawals')->insert([
            'id' => $id, 'user_id' => $owner->id, 'amount' => '200.00', 'status' => $status, 'channel' => 'bKash',
            'destination' => encrypt('01720000093'), 'idempotency_key' => (string) Str::uuid(), 'reviewed_by' => $reviewerId,
            'created_at' => now()->subDay(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function test_commission_settlement_sends_one_email_per_person(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        $settlement = app(CommissionSettlement::class);
        $method = new \ReflectionMethod($settlement, 'notifySettlementCredits');

        $method->invoke($settlement, ['distributed' => [['user_id' => $partner->id, 'amount' => '150.00']]], '2026-10', 'royalty');

        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo(self::Address) && str_contains($mail->noticeBody, '150.00'));
        Mail::assertSent(EventNoticeMail::class, 1);
    }

    public function test_account_creation_and_password_reset_emails_never_carry_a_password(): void
    {
        $this->useRealMail();
        Mail::fake();
        Notification::fake();
        $admin = $this->owner();

        $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'dealer', 'dealer_code' => 'D-DHK-777', 'name' => 'Md. Rafiqul Islam', 'name_bn' => 'মোঃ রফিকুল ইসলাম',
            'email' => 'rafiq.dealer@gmail.com', 'phone' => '01712345678', 'father_name' => 'Late Abdur Rahman', 'mother_name' => 'Rahima Begum',
            'nid' => '19851234567890123', 'pharmacy' => 'Madina Care Point', 'division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Kaliakair',
            'union' => 'Chapair', 'village' => 'Ratanpur', 'post_office' => 'Kaliakair-1750', 'latitude' => '24.0667000', 'longitude' => '90.2167000',
            'status' => 'active', 'reason' => 'Contract verified and authorised.',
        ])->assertRedirect();
        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo('rafiq.dealer@gmail.com') && $mail->noticeTitle === __('event_emails.account_created_title'));

        $dealer = User::query()->where('email', 'rafiq.dealer@gmail.com')->firstOrFail();
        $this->actingAs($admin)->onPortal('sadmin')->postJson('/commands/accounts/'.$dealer->id.'/reset-password', ['password' => 'Chosen-Password-77'])->assertOk();

        $emails = Mail::sent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo('rafiq.dealer@gmail.com'));
        $this->assertCount(2, $emails);
        foreach ($emails as $mail) {
            $this->assertStringNotContainsString('Chosen-Password-77', $mail->noticeBody);
            $this->assertStringNotContainsString('Md', str_replace(['Madina', 'Md. Rafiqul'], '', $mail->noticeBody));
        }
    }

    public function test_a_new_staff_device_sends_an_email(): void
    {
        $this->useRealMail();
        Mail::fake();
        $staff = User::factory()->staff('finance')->create(['email' => 'staff.member@gmail.com']);
        $listener = new NoticeNewStaffDevice;

        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/130.0 Safari/537.36']));
        $listener->handle(new Login('web', $staff, false));
        Mail::assertNothingSent();

        $this->app->instance('request', Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64; rv:128.0) Gecko/20100101 Firefox/128.0']));
        $listener->handle(new Login('web', $staff, false));

        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo('staff.member@gmail.com') && $mail->noticeTitle === __('wave3_ops.new_device_title'));
    }

    public function test_a_support_reply_emails_the_other_side(): void
    {
        $this->useRealMail();
        Mail::fake();
        $partner = $this->partner();
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'email' => 'owner.madina@gmail.com']);
        $ticket = (string) Str::uuid();
        DB::table('support_tickets')->insert(['id' => $ticket, 'user_id' => $partner->id, 'category' => 'other', 'subject' => 'My parcel', 'body' => 'Where is it?', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);

        app(SupportTickets::class)->reply($owner, $ticket, 'It is on the way.');
        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo(self::Address) && str_contains($mail->noticeBody, 'My parcel'));

        app(SupportTickets::class)->reply($partner, $ticket, 'Thank you.');
        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->hasTo('owner.madina@gmail.com') && str_contains($mail->noticeTitle, __('wave3_ops.support_reply_notice_title')));
    }

    public function test_switching_a_prescriber_on_and_off_sends_an_email_only_when_it_changes(): void
    {
        $this->useRealMail();
        Mail::fake();
        $owner = $this->owner();
        $partner = $this->partner();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$partner->id.'/prescriber', ['registration_type' => 'bmdc', 'registration_number' => 'A-1', 'note' => 'Papers checked'])->assertRedirect();
        Mail::assertSent(EventNoticeMail::class, 1);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$partner->id.'/prescriber', ['registration_type' => 'bmdc', 'registration_number' => 'A-2', 'note' => 'Number corrected'])->assertRedirect();
        Mail::assertSent(EventNoticeMail::class, 1);

        app(PrescriberVerification::class)->disable($owner, $partner->fresh(), 'Registration lapsed');
        Mail::assertSent(EventNoticeMail::class, 2);
        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->noticeTitle === __('event_emails.prescriber_disabled_title'));
    }
}
