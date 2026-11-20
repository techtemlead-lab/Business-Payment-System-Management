<?php

namespace Tests\Feature;

use App\Domain\Integrations\SmtpMailConfig;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Notifications\EmailEventSwitches;
use App\Domain\Notifications\MessageTemplateCatalog;
use App\Domain\Notifications\MessageTemplates;
use App\Domain\Notifications\PasswordResetMail;
use App\Domain\Notifications\SmsLength;
use App\Domain\Notifications\SmsText;
use App\Jobs\SendEventEmail;
use App\Mail\EventNoticeMail;
use App\Models\MessageTemplate;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MessageTemplatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    private function owner(): User
    {
        return User::factory()->staff('super_admin')->create();
    }

    private function save(User $actor, string $key, string $body, string $channel = 'sms', string $locale = 'bn', ?string $subject = null): void
    {
        MessageTemplates::save($actor, $key, $channel, $locale, $subject, $body);
    }

    /** @return array<string, string> a value for every placeholder, so nothing is left unfilled */
    private function values(string $key, string $channel): array
    {
        $values = [];
        foreach (MessageTemplateCatalog::placeholdersOf($key, $channel) as $name) {
            $values[$name] = 'X'.$name;
        }

        return $values;
    }

    public function test_every_sms_default_is_identical_to_todays_language_line(): void
    {
        $count = 0;
        foreach (MessageTemplateCatalog::all() as $entry) {
            if ($entry['channel'] !== 'sms') {
                continue;
            }
            $values = $this->values($entry['key'], 'sms');
            $this->assertSame((string) __($entry['key'], $values, 'bn'), SmsText::make($entry['key'], $values), $entry['key']);
            // The default shown in the editor, with its marks filled, is the same line.
            $source = MessageTemplateCatalog::defaultSource($entry['key'], 'sms', 'bn')['body'];
            $this->assertSame((string) __($entry['key'], $values, 'bn'), MessageTemplates::fill($source, $values, $entry['placeholders']), $entry['key']);
            $count++;
        }
        $this->assertGreaterThan(40, $count);
    }

    public function test_every_email_default_is_the_text_the_sender_prepares(): void
    {
        foreach (MessageTemplateCatalog::all() as $entry) {
            if ($entry['channel'] !== 'email') {
                continue;
            }
            foreach (['bn', 'en'] as $locale) {
                $default = MessageTemplateCatalog::defaultSource($entry['key'], 'email', $locale);
                $this->assertNotSame('', trim((string) $default['subject']), $entry['key']);
                $this->assertNotSame('', trim($default['body']), $entry['key']);
                $this->assertSame([], array_diff(MessageTemplateCatalog::tokensIn($default['subject'].' '.$default['body']), $entry['placeholders']), $entry['key'].' uses a mark that is not listed');
                $this->assertSame([], array_diff($entry['required'], MessageTemplateCatalog::tokensIn($default['body'])), $entry['key'].' default drops a required mark');
            }
        }
    }

    public function test_every_template_has_a_title_a_when_line_and_placeholder_labels_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $page = MessageTemplates::page();
            foreach ($page['items'] as $item) {
                $this->assertNotSame('', $item['title']);
                $this->assertStringNotContainsString('message_templates.', $item['title'].$item['when'], $locale.' '.$item['key']);
                $this->assertStringNotContainsString('event_emails.', $item['title'], $locale.' '.$item['key']);
                foreach ($item['placeholders'] as $placeholder) {
                    $this->assertStringNotContainsString('message_templates.', $placeholder['label'], $locale.' '.$placeholder['name']);
                }
            }
        }
    }

    public function test_a_saved_text_is_used_by_the_real_sender_and_reset_brings_the_default_back(): void
    {
        $owner = $this->owner();
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'phone' => '01720000011', 'locale' => 'bn']);
        $this->save($owner, 'sms.account_created', 'নতুন অ্যাকাউন্ট! ইউজার {username}, পাসওয়ার্ড {password}।');

        app(AccountCreatedSms::class)->send($partner, 'Secret123');
        $sent = config('madina.sms.sent');
        $this->assertSame('নতুন অ্যাকাউন্ট! ইউজার 01720000011, পাসওয়ার্ড Secret123।', $sent[0]['message']);

        MessageTemplates::reset($owner, 'sms.account_created', 'sms', 'bn');
        config(['madina.sms.sent' => []]);
        app(AccountCreatedSms::class)->send($partner, 'Secret123');
        $this->assertSame((string) __('sms.account_created', ['username' => '01720000011', 'password' => 'Secret123'], 'bn'), config('madina.sms.sent')[0]['message']);
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_a_text_that_drops_a_required_mark_is_refused(): void
    {
        $owner = $this->owner();
        $problems = MessageTemplates::problems('sms.login_otp', 'sms', 'bn', null, 'আপনার কোড এসে গেছে।');
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('{code}', implode(' ', $problems));

        $this->expectException(ValidationException::class);
        try {
            $this->save($owner, 'sms.login_otp', 'আপনার কোড এসে গেছে।');
        } finally {
            $this->assertSame(0, MessageTemplate::query()->count());
        }
    }

    public function test_the_reset_email_must_keep_its_link(): void
    {
        $problems = MessageTemplates::problems(MessageTemplateCatalog::ResetLinkKey, 'email', 'bn', 'বিষয়', 'পাসওয়ার্ড বদলান।');
        $this->assertStringContainsString('{link}', implode(' ', $problems));
        $this->assertSame([], MessageTemplates::problems(MessageTemplateCatalog::ResetLinkKey, 'email', 'bn', 'বিষয়', 'এখানে চাপুন {link}'));
    }

    public function test_other_bad_texts_are_refused(): void
    {
        $this->assertNotEmpty(MessageTemplates::problems('sms.login_otp', 'sms', 'bn', null, 'Your code {code}'), 'an SMS needs Bangla letters');
        $this->assertNotEmpty(MessageTemplates::problems('sms.login_otp', 'sms', 'bn', null, 'কোড {code} {bogus}'), 'unknown mark');
        $this->assertNotEmpty(MessageTemplates::problems('sms.login_otp', 'sms', 'bn', null, '   '), 'empty');
        $this->assertNotEmpty(MessageTemplates::problems('order.submitted', 'email', 'bn', '', 'লেখা {notice_text}'), 'email needs a subject');
        $this->assertNotEmpty(MessageTemplates::problems('order.submitted', 'email', 'bn', 'বিষয়', 'শুধু নিজের লেখা'), 'the notice text must stay');
        $this->assertNotEmpty(MessageTemplates::problems('nope', 'sms', 'bn', null, 'লেখা'), 'unknown template');
    }

    public function test_a_broken_template_falls_back_to_the_default(): void
    {
        // Rows written around the checks (an old row, or a placeholder removed from the code later).
        MessageTemplate::query()->create(['key' => 'sms.login_otp', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'কোড {code} {gone}', 'active' => true]);
        Cache::flush();
        $this->assertSame((string) __('sms.login_otp', ['code' => '123456', 'minutes' => '5'], 'bn'), SmsText::make('sms.login_otp', ['code' => '123456', 'minutes' => '5']));

        // A mark with no value at send time is broken too.
        MessageTemplate::query()->where('key', 'sms.login_otp')->update(['body' => 'কোড {code} {minutes}']);
        Cache::flush();
        $this->assertSame((string) __('sms.login_otp', ['code' => '123456'], 'bn'), SmsText::make('sms.login_otp', ['code' => '123456']));

        // A switched-off row is ignored.
        MessageTemplate::query()->where('key', 'sms.login_otp')->update(['body' => 'শুধু {code}', 'active' => false]);
        Cache::flush();
        $this->assertSame((string) __('sms.login_otp', ['code' => '1', 'minutes' => '5'], 'bn'), SmsText::make('sms.login_otp', ['code' => '1', 'minutes' => '5']));
    }

    public function test_a_missing_table_never_stops_a_send(): void
    {
        Schema::drop('message_templates');
        Cache::flush();
        $this->assertSame((string) __('sms.login_otp', ['code' => '1', 'minutes' => '5'], 'bn'), SmsText::make('sms.login_otp', ['code' => '1', 'minutes' => '5']));
    }

    public function test_an_email_override_is_used_and_its_values_are_escaped_in_the_html(): void
    {
        $owner = $this->owner();
        $this->save($owner, 'order.submitted', "প্রিয় {name}, আপনার অর্ডার পেয়েছি।\n{notice_text}", 'email', 'bn', 'অর্ডার: {notice_title}');
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'email-smtp.ap-southeast-1.amazonaws.com',
            'mail.from.address' => 'no-reply@madinanhc.com',
            'mail.from.name' => 'Madina Natural Health Care',
        ]);
        Mail::fake();
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'email' => 'rahima.partner@gmail.com', 'locale' => 'bn', 'name' => '<script>alert(1)</script>']);

        (new SendEventEmail($partner->id, 'order.submitted', 'অর্ডার জমা', 'অর্ডার ৫ জমা হয়েছে।'))->handle(app(SmtpMailConfig::class), app(EmailEventSwitches::class));

        Mail::assertSent(EventNoticeMail::class, function (EventNoticeMail $mail): bool {
            $html = $mail->render();

            return $mail->noticeTitle === 'অর্ডার: অর্ডার জমা'
                && str_contains($mail->noticeBody, 'অর্ডার ৫ জমা হয়েছে।')
                && str_contains($html, '&lt;script&gt;')
                && ! str_contains($html, '<script>');
        });
    }

    public function test_an_email_without_an_override_goes_as_prepared(): void
    {
        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'email-smtp.ap-southeast-1.amazonaws.com',
            'mail.from.address' => 'no-reply@madinanhc.com',
        ]);
        Mail::fake();
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'email' => 'rahima.partner@gmail.com', 'locale' => 'bn']);

        (new SendEventEmail($partner->id, 'order.submitted', 'Title', 'Body'))->handle(app(SmtpMailConfig::class), app(EmailEventSwitches::class));

        Mail::assertSent(EventNoticeMail::class, fn (EventNoticeMail $mail): bool => $mail->noticeTitle === 'Title' && $mail->noticeBody === 'Body');
    }

    public function test_the_reset_mail_defaults_and_override(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'bn']);

        $mail = PasswordResetMail::build($user, 'tok123');
        $this->assertSame('পাসওয়ার্ড রিসেট করুন', $mail->subject);
        $this->assertStringContainsString('/reset-password/tok123', (string) $mail->actionUrl);
        $this->assertSame([], array_diff(MessageTemplateCatalog::tokensIn(MessageTemplateCatalog::defaultSource('password.reset_link', 'email', 'bn')['body']), ['link', 'minutes']));

        $this->save($this->owner(), 'password.reset_link', 'নতুন পাসওয়ার্ড দিতে এখানে যান {link}', 'email', 'bn', 'আপনার পাসওয়ার্ড');
        $mail = PasswordResetMail::build($user, 'tok123');
        $this->assertSame('আপনার পাসওয়ার্ড', $mail->subject);
        $html = view($mail->view[0], $mail->viewData + ['message' => new class
        {
            public function embed(): string
            {
                return 'cid:x';
            }
        }])->render();
        $this->assertStringContainsString('নতুন পাসওয়ার্ড দিতে এখানে যান', $html);
        $this->assertStringContainsString('/reset-password/tok123', $html);
    }

    public function test_the_forgot_password_form_sends_through_the_template(): void
    {
        $this->save($this->owner(), 'password.reset_link', 'লিংক {link}', 'email', 'en', 'My subject');
        $user = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'en']);
        Notification::fake();

        $this->onPortal('dealer')->post('/forgot-password', ['email' => $user->email])->assertSessionHas('success');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user): bool {
            return $notification->toMail($user)->subject === 'My subject';
        });
    }

    public function test_the_sms_length_is_counted_in_parts(): void
    {
        $this->assertSame(['characters' => 0, 'parts' => 0, 'per_part' => 160, 'unicode' => false], SmsLength::of(''));
        $this->assertSame(1, SmsLength::of(str_repeat('অ', 70))['parts']);
        $this->assertSame(2, SmsLength::of(str_repeat('অ', 71))['parts']);
        $this->assertSame(2, SmsLength::of(str_repeat('অ', 134))['parts']);
        $this->assertSame(3, SmsLength::of(str_repeat('অ', 135))['parts']);
        $this->assertSame(67, SmsLength::of(str_repeat('অ', 71))['per_part']);
        $this->assertTrue(SmsLength::of('মদিনা')['unicode']);
        $this->assertSame(1, SmsLength::of(str_repeat('a', 160))['parts']);
        $this->assertSame(2, SmsLength::of(str_repeat('a', 161))['parts']);
    }

    public function test_saving_and_resetting_write_audit_lines_and_remember_who_changed_it(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/message-templates', ['key' => 'sms.birthday_greeting', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'শুভ জন্মদিন {name}!'])
            ->assertSessionHasNoErrors();

        $saved = DB::table('audit_events')->where('action', 'message_template.saved')->first();
        $this->assertNotNull($saved);
        $this->assertSame($owner->id, $saved->actor_id);
        $this->assertSame('sms.birthday_greeting', $saved->subject);
        $this->assertSame('শুভ জন্মদিন {name}!', json_decode($saved->metadata, true)['after']);
        $this->assertSame($owner->id, MessageTemplate::query()->firstOrFail()->updated_by);

        $this->actingAs($owner)->onPortal('sadmin')->get('/message-templates')->assertOk()
            ->assertInertia(function (Assert $page): void {
                $page->where('section', 'message-templates');
                $item = collect($page->toArray()['props']['data']['message_templates']['items'])->firstWhere('key', 'sms.birthday_greeting');
                $this->assertTrue($item['locales']['bn']['customised']);
                $this->assertSame('শুভ জন্মদিন {name}!', $item['locales']['bn']['body']);
                $this->assertNotNull($item['locales']['bn']['last_changed']['by']);
            });

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/message-templates/reset', ['key' => 'sms.birthday_greeting', 'channel' => 'sms', 'locale' => 'bn'])->assertSessionHasNoErrors();
        $this->assertSame(0, MessageTemplate::query()->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'message_template.reset')->count());
    }

    public function test_a_refused_text_is_shown_as_an_error_and_not_saved(): void
    {
        $this->actingAs($this->owner())->onPortal('sadmin')
            ->post('/commands/message-templates', ['key' => 'sms.login_otp', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'কোড ছাড়া লেখা'])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_the_owner_and_staff_who_edit_the_website_may_edit_but_others_get_403(): void
    {
        $body = ['key' => 'sms.birthday_greeting', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'শুভ জন্মদিন {name}!'];

        $editor = User::factory()->staff('editor')->create();
        $this->actingAs($editor)->onPortal('manage')->get('/message-templates')->assertOk();
        $this->actingAs($editor)->onPortal('manage')->post('/commands/message-templates', $body)->assertSessionHasNoErrors();
        $this->assertSame(1, MessageTemplate::query()->count());

        $finance = User::factory()->staff('finance')->create();
        $this->actingAs($finance)->onPortal('manage')->get('/message-templates')->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/message-templates', ['body' => 'শুভ জন্মদিন {name}! ভিন্ন'] + $body)->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/message-templates/reset', $body)->assertForbidden();

        foreach (['dealer', 'sub_dealer'] as $role) {
            $partner = User::factory()->create(['role' => $role, 'status' => 'active']);
            $this->actingAs($partner)->onPortal($role === 'dealer' ? 'dealer' : 'subdealer')->post('/commands/message-templates', $body)->assertForbidden();
            $this->actingAs($partner)->onPortal($role === 'dealer' ? 'dealer' : 'subdealer')->post('/commands/message-templates/reset', $body)->assertForbidden();
        }
        $this->assertSame('শুভ জন্মদিন {name}!', MessageTemplate::query()->firstOrFail()->body);
    }

    public function test_a_payroll_only_md_or_chairman_cannot_rewrite_sms_or_email_wording(): void
    {
        $body = ['key' => 'sms.account_created', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'আপনার নাম {username} এবং পাসওয়ার্ড {password}'];
        $reset = ['key' => 'sms.account_created', 'channel' => 'sms', 'locale' => 'bn'];

        foreach (['md', 'chairman'] as $role) {
            $leader = User::factory()->staff($role)->create();
            $this->actingAs($leader)->onPortal('sadmin')->get('/message-templates')->assertForbidden();
            $this->actingAs($leader)->onPortal('sadmin')->post('/commands/message-templates', $body)->assertForbidden();
            $this->actingAs($leader)->onPortal('sadmin')->post('/commands/message-templates/reset', $reset)->assertForbidden();
        }

        $this->assertSame(0, MessageTemplate::query()->count());
    }

    public function test_an_md_granted_website_edit_can_rewrite_the_wording(): void
    {
        $md = User::factory()->staff('md')->create([
            'profile' => ['action_permissions' => ['content' => ['view' => true, 'create' => false, 'edit' => true, 'approve' => false]]],
        ]);
        $body = ['key' => 'sms.account_created', 'channel' => 'sms', 'locale' => 'bn', 'body' => 'আপনার নাম {username} এবং পাসওয়ার্ড {password}'];

        $this->actingAs($md)->onPortal('sadmin')->post('/commands/message-templates', $body)->assertSessionHasNoErrors();

        $this->assertSame($body['body'], MessageTemplate::query()->firstOrFail()->body);
    }
}
