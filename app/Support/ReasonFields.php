<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The owners' switches for every reason and note box in the portals.
 *
 * Each place is compulsory (shown, must be filled), optional (shown, may be left blank) or
 * off (not shown, nothing asked). The money records start as compulsory and every other place
 * as optional, until an owner changes it (the owner's word, 2026-11-13).
 *
 * The owner's own account (Super Admin) is never made to write anything: where a place is
 * compulsory it sees the box and may leave it blank. Off hides the box for everyone.
 *
 * A new reason box needs one line here in `Places`, its name in the `reason_fields` lang group,
 * `ReasonFields::rule('<place>')` in the server rules and `place: '<place>'` on the page.
 */
final class ReasonFields
{
    public const SettingsKey = 'reason_fields';

    public const Required = 'required';

    public const Optional = 'optional';

    public const Off = 'off';

    /** @var list<string> */
    public const Modes = [self::Required, self::Optional, self::Off];

    /**
     * Areas in the order the settings page lists them.
     *
     * @var list<string>
     */
    public const Groups = ['products', 'stock', 'orders', 'payments', 'partners', 'staff', 'money', 'settings', 'clinic'];

    /**
     * Every place a person is asked for a reason or a note, with the area it belongs to.
     *
     * @var array<string, string>
     */
    public const Places = [
        'product.edit' => 'products',
        'product.remove' => 'products',
        'product.taxonomy_remove' => 'products',

        'stock.adjust' => 'stock',
        'stock.count_approve' => 'stock',
        'stock.count_reject' => 'stock',
        'stock.count_cancel' => 'stock',
        'stock.lot_hold' => 'stock',
        'stock.transfer_note' => 'stock',
        'stock.purchase_note' => 'stock',
        'stock.purchase_cancel' => 'stock',
        'stock.purchase_receive_note' => 'stock',
        'stock.supplier_note' => 'stock',
        'stock.branch_note' => 'stock',

        'order.note' => 'orders',
        'order.decision' => 'orders',
        'order.supply_note' => 'orders',
        'order.courier_change' => 'orders',
        'order.delivery_confirm' => 'orders',
        'order.received_back' => 'orders',
        'order.commission_decision' => 'orders',
        'order.refund' => 'orders',

        'payment.verify' => 'payments',
        'payment.statement_confirm' => 'payments',
        'payment.statement_dismiss' => 'payments',
        'payment.account_save' => 'payments',

        'partner.account_create' => 'partners',
        'partner.account_edit' => 'partners',
        'partner.account_delete' => 'partners',
        'partner.benefit_reason' => 'partners',
        'partner.benefit_note' => 'partners',
        'partner.kyc_note' => 'partners',
        'partner.application_review' => 'partners',
        'partner.identity_review' => 'partners',
        'partner.prescriber_note' => 'partners',
        'partner.prescriber_disable' => 'partners',
        'partner.privacy_resolve' => 'partners',
        'partner.scheme_edit' => 'partners',
        'partner.contest_edit' => 'partners',
        'partner.sales_kit_edit' => 'partners',
        'partner.event_notes' => 'partners',
        'partner.event_cancel' => 'partners',
        'partner.customer_note' => 'partners',

        'staff.account_create' => 'staff',
        'staff.account_edit' => 'staff',
        'staff.role_save' => 'staff',
        'staff.access_save' => 'staff',
        'staff.contract' => 'staff',
        'staff.payroll_review' => 'staff',
        'staff.territory_remove' => 'staff',

        'money.withdrawal_review' => 'money',
        'money.withdrawal_settle' => 'money',
        'money.fund_spend_note' => 'money',
        'money.fund_spend_review' => 'money',
        'money.fund_spend_cancel' => 'money',
        'money.reserve_request' => 'money',
        'money.reserve_review' => 'money',
        'money.reserve_pay' => 'money',
        'money.reserve_recover' => 'money',
        'money.held_release' => 'money',
        'money.held_reassign' => 'money',
        'money.opening_hold_release' => 'money',
        'money.opening_verify' => 'money',
        'money.opening_fresh_start' => 'money',
        'money.import_apply' => 'money',
        'money.commission_rules' => 'money',
        'money.policy_notes' => 'money',
        'money.policy_publish' => 'money',
        'money.policy_schedule' => 'money',
        'money.policy_schedule_review' => 'money',
        'money.approval_limit' => 'money',
        'money.withdrawal_minimum' => 'money',
        'money.auto_approval' => 'money',
        'money.kyc_before_withdrawal' => 'money',
        'money.full_payment_when_ordering' => 'money',

        'settings.place_code_remove' => 'settings',
        'settings.integration_provider' => 'settings',
        'settings.integration_startup' => 'settings',
        'settings.email_events' => 'settings',
        'settings.homepage_video' => 'settings',

        'clinic.registration_note' => 'clinic',
        'clinic.patient_notes' => 'clinic',
        'clinic.appointment_note' => 'clinic',
    ];

    /**
     * Places where the written reason is the only record of why money was refunded, withheld,
     * rejected or sent to a fund. They start as compulsory, and the settings page warns when
     * one of them is made optional or switched off.
     *
     * @var list<string>
     */
    public const MoneyRecords = [
        'order.decision', 'order.commission_decision', 'order.refund',
        'payment.verify',
        'partner.benefit_reason',
        'staff.payroll_review',
        'money.withdrawal_review',
        'money.fund_spend_note', 'money.fund_spend_review', 'money.fund_spend_cancel',
        'money.reserve_request', 'money.reserve_review', 'money.reserve_pay', 'money.reserve_recover',
        'money.held_release', 'money.held_reassign', 'money.opening_hold_release',
    ];

    /**
     * The one account that is never made to write a reason or a note: the owner's own, which
     * is the Super Admin. The MD and the Chairman are asked like everyone else (the owner's
     * word, 2026-11-13). Change who is let off here and nowhere else.
     */
    public static function neverForced(?User $person): bool
    {
        return $person !== null && $person->role === 'super_admin';
    }

    /**
     * The validation rule for one place, following the owner's choice for the person signed
     * in. Compulsory rejects a blank box; optional and off both accept one, and off never asks.
     *
     * @param  int  $min  shortest text accepted while the place is compulsory
     * @return list<string>
     */
    public static function rule(string $place, int $max = 1000, int $min = 0): array
    {
        if (self::mode($place) !== self::Required) {
            return ['nullable', 'string', 'max:'.$max];
        }

        return ['required', 'string', ...($min > 1 ? ['min:'.$min] : []), 'max:'.$max];
    }

    /**
     * The owner's choice for one place as it applies to one person: the person signed in, or
     * `$for` when the work runs later without them (a queued job). Compulsory reads as optional
     * for the account that is never forced.
     */
    public static function mode(string $place, ?User $for = null): string
    {
        return self::forPerson(app(self::class)->modes()[self::known($place)], $for ?? self::signedIn());
    }

    /** True when this person must fill the box at this place. */
    public static function required(string $place, ?User $for = null): bool
    {
        return self::mode($place, $for) === self::Required;
    }

    /** What the person wrote, trimmed; an empty string when the box was blank, off or missing. */
    public static function text(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

    /** The reason for a message someone else reads, or "no reason was written" when it is blank. */
    public static function shown(?string $reason, ?string $locale = null): string
    {
        $text = self::text($reason);

        return $text !== '' ? $text : (string) __('reason_fields.not_written', [], $locale);
    }

    /**
     * Every place with the owner's choice, before anyone is let off.
     *
     * @return array<string, string>
     */
    public function modes(): array
    {
        $stored = $this->stored()['modes'];
        $modes = [];
        foreach (array_keys(self::Places) as $place) {
            $modes[$place] = $stored[$place] ?? self::startsAs($place);
        }

        return $modes;
    }

    /** The version the settings page is showing; a save must name it. */
    public function version(): int
    {
        return $this->stored()['version'];
    }

    /**
     * The places that are not optional for this person, for the pages: a place missing from
     * this list is optional.
     *
     * @return array<string, string>
     */
    public function forPages(?User $for): array
    {
        return $this->forPagesFrom(DB::table('business_settings')->where('key', self::SettingsKey)->first(['value', 'version']), $for);
    }

    /**
     * The same list from a settings row the caller already read (null when nothing is saved),
     * so every page does not pay for one more query.
     *
     * @return array<string, string>
     */
    public function forPagesFrom(?object $row, ?User $for): array
    {
        $stored = self::decode($row)['modes'];
        $modes = [];
        foreach (array_keys(self::Places) as $place) {
            $mode = self::forPerson($stored[$place] ?? self::startsAs($place), $for);
            if ($mode !== self::Optional) {
                $modes[$place] = $mode;
            }
        }

        return $modes;
    }

    /**
     * The settings page: every place by area, with its name, its choice and the money warning.
     *
     * @return array{version: int, groups: list<array{key: string, label: string, places: list<array{key: string, label: string, mode: string, money: bool}>}>}
     */
    public function page(): array
    {
        $stored = $this->stored();
        $groups = [];
        foreach (self::Groups as $group) {
            $groups[$group] = ['key' => $group, 'label' => (string) __('reason_fields.group_'.$group), 'places' => []];
        }
        foreach (self::Places as $place => $group) {
            $groups[$group]['places'][] = [
                'key' => $place,
                'label' => self::label($place),
                'mode' => $stored['modes'][$place] ?? self::startsAs($place),
                'money' => in_array($place, self::MoneyRecords, true),
            ];
        }

        return ['version' => $stored['version'], 'groups' => array_values($groups)];
    }

    /**
     * Save the owner's choices. `$version` is the one the owner's page was showing, so two
     * owners saving at once do not silently undo each other. Places left out keep their choice.
     *
     * @param  array<string, string>  $modes
     */
    public function save(User $actor, array $modes, int $version): void
    {
        DB::transaction(function () use ($actor, $modes, $version): void {
            abort_unless($actor->fresh()?->owner(), 403);
            $before = $this->stored();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['version' => __('portal.err_security_setting_stale')]);
            }
            $current = $this->modes();
            $after = $before['modes'];
            $changes = [];
            foreach ($modes as $place => $mode) {
                self::known($place);
                if (! in_array($mode, self::Modes, true)) {
                    throw new InvalidArgumentException('Unknown reason field choice: '.$mode);
                }
                if ($mode === $current[$place]) {
                    continue;
                }
                $changes[$place] = ['before' => $current[$place], 'after' => $mode];
                if ($mode === self::startsAs($place)) {
                    unset($after[$place]);
                } else {
                    $after[$place] = $mode;
                }
            }
            if ($changes === []) {
                return;
            }
            ksort($after);
            $existing = DB::table('business_settings')->where('key', self::SettingsKey)->first(['created_at']);
            DB::table('business_settings')->updateOrInsert(['key' => self::SettingsKey], [
                'value' => json_encode(['modes' => (object) $after], JSON_THROW_ON_ERROR),
                'version' => $version + 1,
                'updated_by' => $actor->id,
                'created_at' => $existing->created_at ?? now(),
                'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'reason_fields.changed', self::SettingsKey, ['changes' => $changes]);
        }, 3);
    }

    /** The place's name on the settings page, in the reader's language. */
    public static function label(string $place): string
    {
        return (string) __('reason_fields.place_'.str_replace('.', '_', self::known($place)));
    }

    private static function startsAs(string $place): string
    {
        return in_array($place, self::MoneyRecords, true) ? self::Required : self::Optional;
    }

    private static function forPerson(string $mode, ?User $person): string
    {
        return $mode === self::Required && self::neverForced($person) ? self::Optional : $mode;
    }

    private static function signedIn(): ?User
    {
        $person = auth()->user();

        return $person instanceof User ? $person : null;
    }

    private static function known(string $place): string
    {
        if (! isset(self::Places[$place])) {
            throw new InvalidArgumentException('Unknown reason field place: '.$place);
        }

        return $place;
    }

    /**
     * The saved choices: only the places an owner moved away from where they start.
     *
     * @return array{modes: array<string, string>, version: int}
     */
    private function stored(): array
    {
        return self::decode(DB::table('business_settings')->where('key', self::SettingsKey)->first(['value', 'version']));
    }

    /** @return array{modes: array<string, string>, version: int} */
    private static function decode(?object $row): array
    {
        $saved = $row === null ? [] : (json_decode((string) $row->value, true, 512, JSON_THROW_ON_ERROR)['modes'] ?? []);
        $modes = [];
        foreach (is_array($saved) ? $saved : [] as $place => $mode) {
            // A place removed from the code later, or a value written by hand, is ignored.
            if (isset(self::Places[$place]) && in_array($mode, self::Modes, true)) {
                $modes[$place] = $mode;
            }
        }

        return ['modes' => $modes, 'version' => (int) ($row->version ?? 0)];
    }
}
