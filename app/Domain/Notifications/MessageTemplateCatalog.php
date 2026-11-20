<?php

namespace App\Domain\Notifications;

use Illuminate\Support\Facades\Lang;

/**
 * Every SMS and email the owner may re-word. The default text of each one stays in the language
 * files (today's exact text); this class only says what each text is, who gets it, which
 * placeholders it may use and which of them it must keep.
 *
 * SMS templates are keyed by their language key ("sms.login_otp"), so SmsText can recognise them.
 * Email templates are keyed by the event name ("order.submitted").
 */
final class MessageTemplateCatalog
{
    public const Sms = 'sms';

    public const Email = 'email';

    public const Locales = ['bn', 'en'];

    /** The reset-link email sent by the "forgot password" form and the set-your-password invitation. */
    public const ResetLinkKey = 'password.reset_link';

    /** The email that carries a reset password when no SMS service took it. */
    public const CredentialsMailKey = 'account.password_reset_credentials';

    /**
     * SMS templates: group => key => [recipient, placeholders that must stay in the text].
     * The placeholders each text may use are read from its default text.
     *
     * @var array<string, array<string, array{0: string, 1: list<string>}>>
     */
    private const SmsGroups = [
        'login' => [
            'sms.login_otp' => ['person', ['code']],
            'sms.owner_confirm_code' => ['person', ['code']],
        ],
        'account' => [
            'sms.account_created' => ['person', ['username', 'password']],
            'sms.password_reset_by_admin' => ['person', ['username', 'password']],
            'sms.account_invitation_sent' => ['person', []],
            'sms.account_suspended' => ['partner', []],
            'sms.account_reactivated' => ['partner', []],
            'sms.account_phone_changed' => ['partner', []],
            'sms.dealer_code_changed' => ['partner', ['code']],
            'sms.geography_updated' => ['partner', []],
            'sms.sponsor_updated' => ['partner', []],
            'sms.benefit_controls_updated' => ['partner', []],
            'sms.kyc_document_uploaded' => ['partner', []],
            'sms.directory_listed' => ['partner', []],
            'sms.directory_unlisted' => ['partner', []],
        ],
        'orders' => [
            'sms.order_submitted' => ['partner', ['number']],
            'sms.order_decision' => ['partner', ['number', 'status']],
            'sms.payment_claimed' => ['partner', ['number']],
            'sms.payment_reviewed' => ['partner', ['number', 'outcome']],
            'sms.shipment_dispatched' => ['partner', ['number']],
            'sms.delivery_confirmed' => ['partner', ['number']],
            'sms.order_refunded' => ['partner', ['number']],
        ],
        'money' => [
            'sms.withdrawal_requested' => ['partner', ['amount']],
            'sms.withdrawal_approved' => ['partner', ['amount']],
            'sms.withdrawal_rejected' => ['partner', ['amount']],
            'sms.withdrawal_paid' => ['partner', ['amount']],
            'sms.commission_settled' => ['partner', ['amount']],
            'sms.commission_matured' => ['partner', ['amount']],
            'sms.commission_released' => ['partner', ['amount']],
            'sms.reward_credited' => ['partner', ['amount']],
            'sms.payroll_paid' => ['staff', ['amount']],
        ],
        'public' => [
            'sms.support_requested' => ['person', []],
            'sms.public_enquiry_received' => ['visitor', []],
            'sms.enquiry_follow_up_contacted' => ['visitor', []],
            'sms.enquiry_follow_up_closed' => ['visitor', []],
            'sms.partnership_application_received' => ['visitor', ['number']],
            'sms.partnership_application_approved' => ['visitor', []],
            'sms.partnership_application_rejected' => ['visitor', []],
            'joining.sms_correction' => ['visitor', ['number']],
        ],
        'recognition' => [
            'sms.rank_achieved' => ['partner', []],
            'sms.milestone_achieved' => ['partner', []],
            'sms.birthday_greeting' => ['person', []],
            'portal.events_sms_published' => ['partner', []],
            'portal.events_sms_cancelled' => ['partner', []],
        ],
        'staff' => [
            'sms.staff_target_saved' => ['staff', []],
            'sms.area_dealer_assigned' => ['partner', []],
            'sms.area_dealer_removed' => ['partner', []],
            'sms.staff_territory_assigned' => ['staff', []],
            'sms.staff_territory_removed' => ['staff', []],
            'sms.policy_published' => ['staff', []],
            'sms.import_applied' => ['staff', []],
        ],
    ];

    /**
     * Email templates: group => event => the language keys of its default text, or null when the
     * default is the portal notice itself ({notice_title} and {notice_text}).
     *
     * @var array<string, array<string, ?array{0: string, 1: string}>>
     */
    private const EmailGroups = [
        'login' => [
            self::ResetLinkKey => ['message_templates.reset_subject', 'message_templates.reset_body'],
        ],
        'account' => [
            'account.created' => ['event_emails.account_created_title', 'event_emails.account_created_body'],
            'account.password_reset' => ['event_emails.password_reset_title', 'event_emails.password_reset_body'],
            self::CredentialsMailKey => ['portal.notice_account_invite_title', 'sms.password_reset_by_admin'],
            'device.new' => null,
            'prescriber.enabled' => ['event_emails.prescriber_enabled_title', 'event_emails.prescriber_enabled_body'],
            'prescriber.disabled' => ['event_emails.prescriber_disabled_title', 'event_emails.prescriber_disabled_body'],
        ],
        'orders' => [
            'order.submitted' => null, 'payment.verified' => null, 'payment.rejected' => null,
            'order.approved' => null, 'order.rejected' => null, 'order.dispatched' => null, 'shipment.delivered' => null,
        ],
        'money' => [
            'withdrawal.requested' => null, 'withdrawal.approved' => null, 'withdrawal.rejected' => null,
            'withdrawal.paid' => null, 'commission.posted' => null,
        ],
        'support' => [
            'support.reply_to_partner' => ['event_emails.support_reply_title', 'event_emails.support_reply_body'],
            'support.reply_to_office' => ['wave3_ops.support_reply_notice_title', 'wave3_ops.support_reply_notice_body'],
        ],
    ];

    /** Placeholders every email may use, and the extra ones some events have. */
    private const EmailCommon = ['name', 'link', 'company'];

    private const EmailExtra = [
        self::ResetLinkKey => ['minutes'],
        self::CredentialsMailKey => ['username', 'password'],
        'support.reply_to_partner' => ['subject'],
        'support.reply_to_office' => ['subject'],
    ];

    /** Required in the email body: the reset link, the new password, and the standard notice text. */
    private const EmailRequired = [
        self::ResetLinkKey => ['link'],
        self::CredentialsMailKey => ['password'],
    ];

    /** Sample values for the preview: placeholder => [Bangla, English]. */
    private const Samples = [
        'name' => ['রহিম উদ্দিন', 'Rahim Uddin'], 'company' => ['মদিনা ন্যাচারাল হেলথ কেয়ার', 'Madina Natural Health Care'],
        'link' => ['https://madinanhc.com/reset-password/abc123', 'https://madinanhc.com/reset-password/abc123'],
        'code' => ['৪৮২৯১৭', '482917'], 'minutes' => ['৫', '5'], 'seconds' => ['৩০', '30'],
        'username' => ['০১৭১২৩৪৫৬৭৮', '01712345678'], 'password' => ['Ab12cd34', 'Ab12cd34'],
        'amount' => ['১২,৫০০', '12,500'], 'number' => ['ORD-1024', 'ORD-1024'], 'status' => ['অনুমোদিত', 'Approved'],
        'reason' => ['কাগজ স্পষ্ট নয়', 'The paper is not clear'], 'outcome' => ['যাচাই হয়েছে', 'verified'],
        'channel' => ['বিকাশ', 'bKash'], 'topic' => ['পণ্যের দাম', 'Product price'], 'kind' => ['স্টাফ কমিশন', 'Staff commission'],
        'month' => ['নভেম্বর ২০২৬', 'November 2026'], 'courier' => ['সুন্দরবন কুরিয়ার', 'Sundarban Courier'],
        'tracking' => ['SB-55821', 'SB-55821'], 'program' => ['ঈদ পুরস্কার', 'Eid reward'], 'category' => ['কমিশন', 'Commission'],
        'role' => ['ডিলার', 'Dealer'], 'rank' => ['সিলভার', 'Silver'], 'milestone' => ['পাঁচ লাখ টাকা', 'Five lakh taka'],
        'zone' => ['পীরগঞ্জ', 'Pirganj'], 'territory' => ['পীরগঞ্জ উপজেলা', 'Pirganj Upazila'], 'level' => ['উপজেলা', 'Upazila'],
        'quota' => ['৫০,০০০', '50,000'], 'target' => ['১,০০,০০০', '100,000'], 'bonus' => ['২,০০০', '2,000'],
        'summary' => ['কমিশনের হার বদলেছে', 'Commission rate changed'], 'sponsor' => ['করিম মিয়া', 'Karim Mia'],
        'place' => ['রংপুর, পীরগঞ্জ', 'Rangpur, Pirganj'], 'type' => ['পণ্যের তালিকা', 'Product list'],
        'reference' => ['IMP-2026-11', 'IMP-2026-11'], 'account_type' => ['ডিলার', 'Dealer'],
        'title' => ['পার্টনার মিটিং', 'Partner meeting'], 'date' => ['২০ নভেম্বর', '20 November'], 'time' => ['সকাল ১০টা', '10:00 am'],
        'notice_title' => ['আপনার অর্ডার অনুমোদিত হয়েছে', 'Your order was approved'],
        'notice_text' => ['অর্ডার ORD-1024 অনুমোদিত হয়েছে। প্যানেলে বিস্তারিত দেখুন।', 'Order ORD-1024 was approved. See the details in your panel.'],
    ];

    /**
     * Every template the owner can edit, grouped for the screen.
     *
     * @return list<array{group: string, key: string, channel: string, recipient: string, required: list<string>, placeholders: list<string>}>
     */
    public static function all(): array
    {
        $rows = [];
        foreach (self::SmsGroups as $group => $items) {
            foreach ($items as $key => [$recipient, $required]) {
                $rows[] = ['group' => $group, 'key' => $key, 'channel' => self::Sms, 'recipient' => $recipient, 'required' => $required, 'placeholders' => self::placeholdersOf($key, self::Sms)];
            }
        }
        foreach (self::EmailGroups as $group => $items) {
            foreach (array_keys($items) as $key) {
                $rows[] = ['group' => $group, 'key' => $key, 'channel' => self::Email, 'recipient' => in_array($key, ['support.reply_to_office'], true) ? 'staff' : 'person', 'required' => self::EmailRequired[$key] ?? self::noticeRequired($key), 'placeholders' => self::placeholdersOf($key, self::Email)];
            }
        }

        return $rows;
    }

    /** @return array{group: string, key: string, channel: string, recipient: string, required: list<string>, placeholders: list<string>}|null */
    public static function find(string $key, string $channel): ?array
    {
        foreach (self::all() as $row) {
            if ($row['key'] === $key && $row['channel'] === $channel) {
                return $row;
            }
        }

        return null;
    }

    public static function handlesSms(string $key): bool
    {
        foreach (self::SmsGroups as $items) {
            if (isset($items[$key])) {
                return true;
            }
        }

        return false;
    }

    /** SMS that carry a login code: the gateway lets these go out without the Bangla-letters check. */
    public static function isCodeSms(string $key): bool
    {
        return in_array($key, ['sms.login_otp', 'sms.owner_confirm_code'], true);
    }

    /**
     * The placeholders a template may use.
     *
     * @return list<string>
     */
    public static function placeholdersOf(string $key, string $channel): array
    {
        if ($channel === self::Sms) {
            return self::tokensIn(self::defaultSource($key, self::Sms, 'bn')['body']);
        }
        $names = [...self::EmailCommon, ...(self::EmailExtra[$key] ?? [])];
        if (self::emailDefaultKeys($key) === null) {
            $names = [...$names, 'notice_title', 'notice_text'];
        }

        return array_values(array_unique($names));
    }

    /**
     * What an email event's text must keep: the standard notice text, when the default is the notice.
     *
     * @return list<string>
     */
    private static function noticeRequired(string $key): array
    {
        return self::emailDefaultKeys($key) === null ? ['notice_text'] : [];
    }

    /** @return array{0: string, 1: string}|null */
    private static function emailDefaultKeys(string $key): ?array
    {
        foreach (self::EmailGroups as $items) {
            if (array_key_exists($key, $items)) {
                return $items[$key];
            }
        }

        return null;
    }

    /**
     * Today's text, with {placeholder} marks in place of the language files' :placeholder marks.
     *
     * @return array{subject: ?string, body: string}
     */
    public static function defaultSource(string $key, string $channel, string $locale): array
    {
        if ($channel === self::Sms) {
            return ['subject' => null, 'body' => self::toBraces((string) Lang::get($key, [], $locale))];
        }
        $keys = self::emailDefaultKeys($key);
        if ($keys === null) {
            return ['subject' => '{notice_title}', 'body' => '{notice_text}'];
        }

        return [
            'subject' => self::toBraces((string) Lang::get($keys[0], [], $locale)),
            'body' => self::toBraces((string) Lang::get($keys[1], [], $locale)),
        ];
    }

    /** ":code" in a language line becomes "{code}". */
    public static function toBraces(string $text): string
    {
        return (string) preg_replace('/(?<![\p{L}\p{N}_]):([a-z][a-z_]*)/u', '{$1}', $text);
    }

    /**
     * @return list<string>
     */
    public static function tokensIn(string $text): array
    {
        preg_match_all('/\{([a-z][a-z0-9_]*)\}/', $text, $found);

        return array_values(array_unique($found[1]));
    }

    /** The sample value shown in the preview. */
    public static function sample(string $name, string $locale): string
    {
        return self::Samples[$name][$locale === 'en' ? 1 : 0] ?? '…';
    }

    /** @return list<string> */
    public static function groupOrder(): array
    {
        return array_values(array_unique([...array_keys(self::SmsGroups), ...array_keys(self::EmailGroups)]));
    }
}
