<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginOtpChallenge;
use App\Domain\Content\PartnerEventNotifications;
use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\IntegrationSettings;
use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Notifications\BirthdayGreetings;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\PartnerEvent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Greenweb is never called for real: every request goes to Http::fake().
 */
class GreenwebSmsTest extends TestCase
{
    use RefreshDatabase;

    private const Token = 'greenweb-test-token-7f3a9';

    private const BanglaText = 'মদিনা: আপনার অর্ডার জমা হয়েছে।';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        config(['madina.financial_activation' => false, 'madina.bdris.enabled' => false]);
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logged[] = $log->message.' '.json_encode($log->context, JSON_UNESCAPED_UNICODE);
        });
    }

    private function enableGreenweb(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'greenweb',
            'version' => 0,
            'enabled' => true,
            'base_url' => 'https://api.bdbulksms.net/api.php',
            'balance_url' => 'https://api.bdbulksms.net/g_api.php',
            'api_key' => self::Token,
            'reason' => 'Greenweb is the SMS service the owner chose.',
        ])->assertSessionHasNoErrors();
    }

    private function englishPartner(array $overrides = []): User
    {
        return User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000088',
            'locale' => 'en',
            ...$overrides,
        ]);
    }

    private function useArrayDriverInEnglish(): void
    {
        app()->setLocale('en');
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    private function assertLastSmsIsBangla(string $event): void
    {
        $sent = collect(config('madina.sms.sent'))->last();
        $this->assertIsArray($sent, 'No SMS was recorded for '.$event);
        $this->assertTrue(SmsText::hasBanglaLetters((string) $sent['message']), $event.' was not in Bangla: '.$sent['message']);
    }

    public function test_greenweb_is_in_the_catalog_and_uses_https_by_default(): void
    {
        $provider = IntegrationCatalog::groups()['sms']['providers']['greenweb'];

        $this->assertFalse($provider['defaults']['enabled']);
        $this->assertSame('https://api.bdbulksms.net/api.php', $provider['defaults']['base_url']);
        $this->assertContains('api_key', $provider['fields']);
    }

    public function test_the_token_is_stored_encrypted_and_never_shown_back(): void
    {
        $this->enableGreenweb();

        $settings = app(IntegrationSettings::class);
        $this->assertSame(self::Token, $settings->provider('sms', 'greenweb')['api_key']);
        $this->assertStringNotContainsString(self::Token, (string) json_encode(DB::table('business_settings')->get()));
        $this->assertStringNotContainsString(self::Token, (string) json_encode($settings->snapshot(false)));
    }

    public function test_an_ok_reply_counts_as_sent_and_the_token_goes_in_the_form_body(): void
    {
        $this->enableGreenweb();
        Http::fake(['api.bdbulksms.net/*' => Http::response('Ok: SMS Sent Successfully To +8801720000088')]);

        $result = app(SmsGatewayClient::class)->send('8801720000088', self::BanglaText);

        $this->assertTrue($result['sent']);
        $this->assertSame('greenweb', $result['provider']);
        $this->assertDatabaseHas('sms_logs', ['provider' => 'greenweb', 'sent' => true, 'refused' => false]);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'https://api.bdbulksms.net/api.php'
            && $request['token'] === self::Token
            && $request['to'] === '01720000088'
            && $request['message'] === self::BanglaText);
    }

    public function test_an_error_reply_with_http_200_is_not_treated_as_sent(): void
    {
        $this->enableGreenweb();
        Http::fake(['api.bdbulksms.net/*' => Http::response('Error: +8801720000088 Invalid Number !')]);

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertFalse($result['sent']);
        $this->assertFalse($result['refused']);
        $this->assertDatabaseHas('sms_logs', ['provider' => null, 'sent' => false, 'refused' => false]);
    }

    public function test_a_blank_or_unknown_reply_with_http_200_is_not_treated_as_sent(): void
    {
        $this->enableGreenweb();
        Http::fakeSequence('api.bdbulksms.net/*')->push('')->push('Forbidden')->push("Ok: SMS Sent\nError: Invalid Number !");

        $client = app(SmsGatewayClient::class);
        $this->assertFalse($client->send('01720000088', self::BanglaText)['sent']);
        $this->assertFalse($client->send('01720000088', self::BanglaText)['sent']);
        $this->assertFalse($client->send('01720000088', self::BanglaText)['sent']);
    }

    public function test_the_token_and_message_are_never_logged(): void
    {
        $this->enableGreenweb();
        $this->logged = [];
        $replyEchoingSecrets = 'Error: bad token '.self::Token.' for '.self::BanglaText.' to 01720000088';
        Http::fakeSequence('api.bdbulksms.net/*')
            ->push($replyEchoingSecrets)
            ->pushResponse(Http::failedConnection('cURL error 28 for https://api.bdbulksms.net/api.php?token='.self::Token));

        app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);
        app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertNotEmpty($this->logged);
        $everything = implode("\n", $this->logged);
        $this->assertStringNotContainsString(self::Token, $everything);
        $this->assertStringNotContainsString(self::BanglaText, $everything);
        $this->assertStringNotContainsString('01720000088', $everything);
    }

    public function test_a_text_greenweb_rejects_is_not_counted_as_delivered_or_written_to_the_log(): void
    {
        $this->enableGreenweb();
        config(['madina.sms.driver' => 'log']);
        $this->logged = [];
        Http::fake(['api.bdbulksms.net/*' => Http::response('Error: Insufficient Balance')]);

        $delivered = app(PartnerEventSms::class)->send($this->englishPartner(), 'order.submitted', self::BanglaText);

        $this->assertFalse($delivered);
        $this->assertStringNotContainsString(self::BanglaText, implode("\n", $this->logged));
    }

    public function test_balance_is_read_from_the_reply_and_an_error_reply_is_not_a_balance(): void
    {
        $this->enableGreenweb();
        Http::fakeSequence('api.bdbulksms.net/*')->push('125.50')->push('Error: Invalid token');

        $client = app(SmsGatewayClient::class);
        $this->assertSame('125.50', $client->balance('greenweb')['balance']);
        $this->assertFalse($client->balance('greenweb')['ok']);
    }

    public function test_a_balance_failure_does_not_log_the_token(): void
    {
        $this->enableGreenweb();
        $this->logged = [];
        Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.bdbulksms.net/g_api.php?token='.self::Token.'&balance'));

        $this->assertFalse(app(SmsGatewayClient::class)->balance('greenweb')['ok']);
        $this->assertStringNotContainsString(self::Token, implode("\n", $this->logged));
    }

    public function test_an_english_only_message_is_refused_before_any_provider_is_called(): void
    {
        $this->enableGreenweb();
        $this->logged = [];
        Http::fake();
        $text = 'Your order has been submitted.';

        $result = app(SmsGatewayClient::class)->send('01720000088', $text);

        $this->assertFalse($result['sent']);
        $this->assertTrue($result['refused']);
        $this->assertTrue(SmsText::hasBanglaLetters($result['message']), 'The refusal is reported in Bangla.');
        Http::assertNothingSent();
        $this->assertDatabaseHas('sms_logs', ['refused' => true, 'sent' => false, 'provider' => null, 'phone_masked' => '••••0088', 'length' => mb_strlen($text)]);
        $this->assertStringNotContainsString($text, implode("\n", $this->logged));
        $this->assertStringContainsString('sms.refused_not_bangla', implode("\n", $this->logged));
    }

    public function test_banglish_and_bangla_digits_alone_do_not_count_as_bangla(): void
    {
        foreach (['Apnar order jama hoyeche', 'Paid ৳৫০০', '১২৩৪'] as $text) {
            $this->assertFalse(SmsText::hasBanglaLetters($text), $text);
        }
        foreach (['আপনার order জমা হয়েছে', 'মদিনা'] as $text) {
            $this->assertTrue(SmsText::hasBanglaLetters($text), $text);
        }
    }

    public function test_bangla_mixed_with_english_words_is_allowed(): void
    {
        $this->enableGreenweb();
        Http::fake(['api.bdbulksms.net/*' => Http::response('Ok: SMS Sent Successfully To +8801720000088')]);

        $this->assertTrue(app(SmsGatewayClient::class)->send('01720000088', 'আপনার order জমা হয়েছে')['sent']);
    }

    public function test_only_an_otp_may_go_without_bangla(): void
    {
        $this->enableGreenweb();
        Http::fake(['api.bdbulksms.net/*' => Http::response('Ok: SMS Sent Successfully To +8801720000088')]);

        $this->assertTrue(app(SmsGatewayClient::class)->send('01720000088', 'Code 123456', true)['sent']);
        $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', 'Code 123456')['sent']);
    }

    public function test_a_refused_message_is_reported_and_never_falls_through_to_the_log_driver(): void
    {
        $this->useArrayDriverInEnglish();
        $partner = $this->englishPartner();

        $this->assertFalse(app(PartnerEventSms::class)->send($partner, 'order.submitted', 'Your order is in.'));
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_every_sms_template_has_bangla_text(): void
    {
        $bangla = require lang_path('bn/sms.php');
        $english = require lang_path('en/sms.php');
        $this->assertSame([], array_values(array_diff(array_keys($english), array_keys($bangla))), 'Every SMS template needs a Bangla version.');

        foreach ($bangla as $key => $text) {
            $this->assertTrue(SmsText::hasBanglaLetters($text), 'sms.'.$key);
        }
        foreach (['events_sms_published', 'events_sms_cancelled'] as $key) {
            $this->assertTrue(SmsText::hasBanglaLetters(SmsText::make('portal.'.$key)), $key);
        }
    }

    public function test_no_sender_builds_sms_text_in_the_users_language(): void
    {
        // AccountCreatedSms keeps __() only for its fallback email, which stays in the person's own language.
        $finder = (new Finder)->files()->in(app_path())->name('*.php')->notName('AccountCreatedSms.php');
        $offenders = [];
        foreach ($finder as $file) {
            if (preg_match("/__\(\s*(?:'sms\.(?!err_)|\\\$template\b|\\\$visible \? 'sms\.)/", $file->getContents()) === 1) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Use SmsText::make() for SMS text.');
    }

    public function test_login_code_is_in_bangla_for_an_english_user(): void
    {
        $this->useArrayDriverInEnglish();

        $result = app(LoginOtpChallenge::class)->issue($this->englishPartner(), 'dealer');

        $this->assertTrue($result['sent']);
        $this->assertLastSmsIsBangla('login.otp');
    }

    public function test_account_created_and_password_reset_are_in_bangla_for_an_english_user(): void
    {
        $this->useArrayDriverInEnglish();
        $partner = $this->englishPartner();
        $sender = app(AccountCreatedSms::class);

        $this->assertTrue($sender->send($partner, 'Temp-Pass-123'));
        $this->assertLastSmsIsBangla('account.created');
        $this->assertTrue($sender->sendPasswordReset($partner, 'Temp-Pass-456'));
        $this->assertLastSmsIsBangla('account.password_reset');
    }

    public function test_birthday_greeting_is_in_bangla_for_an_english_user(): void
    {
        $this->useArrayDriverInEnglish();
        $today = CarbonImmutable::parse('2026-11-08', 'Asia/Dhaka');
        $this->englishPartner(['profile' => ['date_of_birth' => '1990-11-08']]);

        $result = app(BirthdayGreetings::class)->sendForToday($today);

        $this->assertSame(1, $result['sent']);
        $this->assertLastSmsIsBangla(BirthdayGreetings::Event);
        $this->assertStringContainsString('শুভ জন্মদিন', collect(config('madina.sms.sent'))->last()['message']);
    }

    public function test_event_sms_uses_the_bangla_title_for_an_english_user(): void
    {
        $this->useArrayDriverInEnglish();
        $partner = $this->englishPartner();
        $event = PartnerEvent::factory()->create(['title_bn' => 'মাসিক সভা', 'title_en' => 'Monthly meeting', 'send_sms' => true, 'status' => 'published']);

        app(PartnerEventNotifications::class)->sendSms($event, [$partner->id], 'event.published', 'portal.events_sms_published');

        $message = collect(config('madina.sms.sent'))->last()['message'];
        $this->assertStringContainsString('মাসিক সভা', $message);
        $this->assertStringNotContainsString('Monthly meeting', $message);
    }

    public function test_status_words_inside_an_sms_are_in_bangla_for_an_english_user(): void
    {
        $this->useArrayDriverInEnglish();
        $partner = $this->englishPartner();

        $this->actingAs($partner)->onPortal('dealer')->post('/commands/partner-support', [
            'topic' => 'complaint',
            'message' => 'Please help update my shop address on file.',
        ])->assertRedirect();

        $message = collect(config('madina.sms.sent'))->firstWhere('event', 'support.requested')['message'];
        $this->assertStringContainsString((string) __('portal.support_topic_complaint', [], 'bn'), $message);
        $this->assertStringNotContainsString((string) __('portal.support_topic_complaint', [], 'en'), $message);
    }

    /** @return array<string, mixed> */
    private function eventForm(array $overrides = []): array
    {
        return [
            'title_bn' => 'মাসিক ডিলার সভা',
            'title_en' => 'Monthly dealer meeting',
            'date' => '2026-11-12',
            'start_time' => '10:00',
            'end_time' => '12:00',
            'place' => 'Pirganj office',
            'audience' => 'all',
            ...$overrides,
        ];
    }

    private function eventEditor(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    public function test_the_composer_rejects_an_english_only_title_with_a_bangla_error_when_sms_is_on(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Dhaka'));
        app()->setLocale('en');

        $response = $this->actingAs($this->eventEditor())->onPortal('sadmin')
            ->post('/commands/events', $this->eventForm(['title_bn' => 'Monthly meeting', 'send_sms' => true]));

        $response->assertSessionHasErrors('title_bn');
        $this->assertTrue(SmsText::hasBanglaLetters((string) session('errors')->first('title_bn')));
        $this->assertSame(0, PartnerEvent::query()->count());
    }

    public function test_the_composer_rejects_banglish_when_sms_is_on(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Dhaka'));

        $this->actingAs($this->eventEditor())->onPortal('sadmin')
            ->post('/commands/events', $this->eventForm(['title_bn' => 'Masik sobha', 'send_sms' => true]))
            ->assertSessionHasErrors('title_bn');
    }

    public function test_an_english_title_is_still_allowed_when_no_sms_is_sent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-10 09:00', 'Asia/Dhaka'));

        $this->actingAs($this->eventEditor())->onPortal('sadmin')
            ->post('/commands/events', $this->eventForm(['title_bn' => 'Monthly meeting']))
            ->assertSessionHasNoErrors();
        $this->assertSame(1, PartnerEvent::query()->count());
    }

    public function test_an_older_draft_with_an_english_title_cannot_be_published_with_sms(): void
    {
        $event = PartnerEvent::factory()->create(['title_bn' => 'Monthly meeting', 'send_sms' => true, 'status' => 'draft']);

        $this->actingAs($this->eventEditor())->onPortal('sadmin')
            ->post('/commands/events/'.$event->id.'/publish')
            ->assertSessionHasErrors('title_bn');
        $this->assertSame('draft', $event->fresh()->status);
    }

    public function test_an_event_sms_with_an_english_title_is_not_sent_but_logged_without_the_title(): void
    {
        $this->useArrayDriverInEnglish();
        $this->logged = [];
        $partner = $this->englishPartner();
        $event = PartnerEvent::factory()->create(['title_bn' => 'Monthly meeting', 'send_sms' => true, 'status' => 'published']);

        app(PartnerEventNotifications::class)->sendSms($event, [$partner->id], 'event.cancelled', 'portal.events_sms_cancelled');

        $this->assertSame([], config('madina.sms.sent'));
        $this->assertStringContainsString('partner.event.sms.refused_not_bangla', implode("\n", $this->logged));
        $this->assertStringNotContainsString('Monthly meeting', implode("\n", $this->logged));
    }

    public function test_the_staff_calendar_gets_the_bangla_rules_even_on_an_english_page(): void
    {
        app()->setLocale('en');

        $this->actingAs($this->eventEditor())->onPortal('sadmin')->get('/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.events_calendar.sms_compose.rules_note', SmsText::make('portal.sms_compose_rules_note'))
                ->where('data.events_calendar.sms_compose.error', SmsText::make('portal.sms_err_bangla_required')));
    }
}
