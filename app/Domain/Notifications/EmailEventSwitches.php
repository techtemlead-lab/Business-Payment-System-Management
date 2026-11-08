<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The owner's on/off switch for each email the system can send. Every event is on until
 * the owner turns it off, so only the events switched off are stored.
 */
final class EmailEventSwitches
{
    public const SettingsKey = 'email_event_switches';

    /** Every event that can send an email, in the order the owner sees them. */
    public const Events = [
        'account.created',
        'order.submitted',
        'payment.verified',
        'payment.rejected',
        'order.approved',
        'order.rejected',
        'order.dispatched',
        'shipment.delivered',
        'withdrawal.requested',
        'withdrawal.approved',
        'withdrawal.rejected',
        'withdrawal.paid',
        'commission.posted',
        'account.password_reset',
        'device.new',
        'support.reply_to_partner',
        'support.reply_to_office',
        'prescriber.enabled',
        'prescriber.disabled',
    ];

    public function isOn(string $event): bool
    {
        return in_array($event, self::Events, true) && ! in_array($event, $this->stored()['off'], true);
    }

    /**
     * What the Service integrations page shows on the email tab.
     *
     * @return array{version: int, mail_ready: bool, events: list<array{key: string, label: string, enabled: bool}>, failures: list<array{id: int, event: string, recipient: string, reason: ?string, created_at: string}>, text: array<string, string>}
     */
    public function panel(): array
    {
        $stored = $this->stored();

        return [
            'version' => $stored['version'],
            'mail_ready' => PartnerEventEmail::realMailConfigured(),
            'events' => array_map(fn (string $event): array => [
                'key' => $event,
                'label' => self::label($event),
                'enabled' => ! in_array($event, $stored['off'], true),
            ], self::Events),
            'failures' => EmailDeliveryLog::recentFailures(),
            'text' => [
                'title' => __('event_emails.panel_title'),
                'hint' => __('event_emails.panel_hint'),
                'not_ready' => __('event_emails.panel_not_ready'),
                'reason' => __('event_emails.panel_reason'),
                'save' => __('event_emails.panel_save'),
                'saving' => __('event_emails.panel_saving'),
                'failures_title' => __('event_emails.failures_title'),
                'failures_hint' => __('event_emails.failures_hint'),
                'failures_none' => __('event_emails.failures_none'),
                'failures_when' => __('event_emails.failures_when'),
                'failures_event' => __('event_emails.failures_event'),
                'failures_to' => __('event_emails.failures_to'),
                'failures_reason' => __('event_emails.failures_reason'),
            ],
        ];
    }

    public static function label(string $event): string
    {
        return (string) __('event_emails.label_'.str_replace('.', '_', $event));
    }

    /**
     * @param  list<string>  $enabledEvents  The events the owner left switched on.
     */
    public function save(User $actor, array $enabledEvents, int $version, string $reason): void
    {
        DB::transaction(function () use ($actor, $enabledEvents, $version, $reason): void {
            // Mail is one of the owner-only gateway groups (see IntegrationSettings).
            abort_unless($actor->fresh()?->role === 'super_admin', 403);
            $existing = DB::table('business_settings')->where('key', self::SettingsKey)->lockForUpdate()->first();
            if ((int) ($existing->version ?? 0) !== $version) {
                throw ValidationException::withMessages(['version' => __('portal.si_err_stale_version')]);
            }
            $off = array_values(array_diff(self::Events, $enabledEvents));
            $row = [
                'value' => json_encode(['off' => $off], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'updated_at' => now(),
            ];
            if ($existing) {
                DB::table('business_settings')->where('key', self::SettingsKey)->update($row);
            } else {
                DB::table('business_settings')->insert([...$row, 'key' => self::SettingsKey, 'created_at' => now()]);
            }
            Audit::record($actor->id, 'integrations.email_events_saved', self::SettingsKey, ['off' => $off, 'reason' => $reason]);
        }, 3);
    }

    /** @return array{off: list<string>, version: int} */
    private function stored(): array
    {
        $row = DB::table('business_settings')->where('key', self::SettingsKey)->first();
        $value = $row ? json_decode((string) $row->value, true) : null;
        $off = is_array($value) && is_array($value['off'] ?? null) ? array_values(array_filter($value['off'], 'is_string')) : [];

        return ['off' => $off, 'version' => (int) ($row->version ?? 0)];
    }
}
