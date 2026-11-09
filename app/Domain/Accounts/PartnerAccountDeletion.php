<?php

namespace App\Domain\Accounts;

use App\Domain\Auth\LoginOtpChallenge;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\User;
use App\Support\Audit;
use App\Support\PortalHost;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The owner's way to remove a dealer or sub-dealer.
 *
 * A partner with no history is deleted for good. A partner with history is closed instead:
 * signed out, suspended and hidden, with every record kept. History is read from the database
 * schema, so any table that points at users protects the account unless it is listed here as
 * disposable. Orders, payments, ledger, commissions, withdrawals and audit logs are never deleted.
 */
final class PartnerAccountDeletion
{
    public const OutcomeDeleted = 'deleted';

    public const OutcomeClosed = 'closed';

    /**
     * Rows that exist only to run a live account and always go with it.
     *
     * @var list<string>
     */
    public const DisposableColumns = [
        'account_notices.user_id',
        'area_dealer_assignments.user_id',
        'known_sign_in_devices.user_id',
        'kyc_documents.user_id',
        'list_column_choices.user_id',
        'partner_anniversary_greetings.user_id',
        'partner_birthday_greetings.user_id',
        'partner_former_codes.user_id',
        'referral_tree_paths.descendant_id',
        'saved_list_filters.user_id',
        'sessions.user_id',
        'tour_completions.user_id',
    ];

    /**
     * Columns where only some rows are disposable, such as the empty wallet every new partner
     * is given. {@see self::historyRows()} says which rows count as history.
     *
     * @var list<string>
     */
    public const PartlyDisposableColumns = [
        'dealer_applications.user_id',
        'dealer_ranks.user_id',
        'kyc_documents.uploaded_by',
        'referral_tree_paths.ancestor_id',
        'wallets.user_id',
    ];

    /**
     * Columns that point at a user whether or not the database has a foreign key for them.
     *
     * @var list<string>
     */
    public const UnconstrainedColumns = ['users.sponsor_id'];

    /** The history shown by name to the owner. Everything else is summed as "other records". */
    private const RecordKeys = [
        'orders.user_id' => 'orders',
        'payout_receipts.user_id' => 'payments',
        'wallets.user_id' => 'wallet',
        'withdrawals.user_id' => 'withdrawals',
        'journal_lines.user_id' => 'ledger',
        'commission_allocations.user_id' => 'commissions',
        'users.sponsor_id' => 'people_under',
        'referral_tree_paths.ancestor_id' => 'people_under',
        'partner_customers.partner_id' => 'customers',
        'partner_conversations.partner_id' => 'messages',
        'support_tickets.user_id' => 'support',
        'prescriptions.prescriber_id' => 'prescriptions',
        'partner_agreement_acceptances.user_id' => 'agreement',
        'audit_events.actor_id' => 'activity',
    ];

    public function __construct(
        private LoginOtpChallenge $loginCodes,
        private PartnerEventSms $sms,
    ) {}

    public static function canUse(User $user): bool
    {
        return $user->status === 'active' && $user->owner();
    }

    public static function isPartner(User $account): bool
    {
        return in_array($account->role, ['dealer', 'sub_dealer'], true);
    }

    /**
     * What deleting this partner would do, for the confirmation box.
     *
     * @return array{outcome: string, dealer_code: ?string, records: list<array{key: string, label: string, count: int}>}
     */
    public function preview(User $partner): array
    {
        return $this->outcomeOf($partner, $this->historyCounts($partner->id));
    }

    /**
     * Delete the partner, or close the account when it has history.
     *
     * @return array{outcome: string, dealer_code: ?string, records: list<array{key: string, label: string, count: int}>}
     *
     * @throws ValidationException when the typed code is not the partner's dealer code
     */
    public function remove(User $actor, User $partner, string $reason, string $typedCode): array
    {
        abort_unless(self::canUse($actor) && self::isPartner($partner), 403);

        $newlySuspended = false;
        $result = DB::transaction(function () use ($actor, $partner, $reason, $typedCode, &$newlySuspended): array {
            CommissionWallet::lock();
            $locked = User::whereKey($partner->id)->lockForUpdate()->firstOrFail();
            abort_unless(self::isPartner($locked), 403);
            $this->assertCodeMatches($locked, $typedCode);

            $history = $this->historyCounts($locked->id);
            $outcome = $this->outcomeOf($locked, $history);
            if ($history === []) {
                $this->deleteAccount($actor, $locked, $reason);
            } else {
                $newlySuspended = $locked->status !== 'suspended';
                $this->closeAccount($actor, $locked, $reason, $history);
            }

            return $outcome;
        }, 3);

        if ($newlySuspended) {
            $this->tellPartnerAccountWasClosed($partner, $reason);
        }

        return $result;
    }

    /**
     * Every column that keeps an account when it has a row pointing at the partner: all foreign
     * keys to users.id and the known unconstrained columns, minus the disposable ones.
     *
     * @return list<string>
     */
    public function historyColumns(): array
    {
        return collect([...$this->userForeignKeys(), ...self::UnconstrainedColumns])
            ->unique()
            ->reject(fn (string $reference): bool => in_array($reference, self::DisposableColumns, true))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Every foreign key to users.id in the current schema, as table.column.
     *
     * @return list<string>
     */
    public function userForeignKeys(): array
    {
        $rows = DB::select(<<<'SQL'
            SELECT child.relname AS table_name, referencing.attname AS column_name
            FROM pg_constraint fk
            JOIN pg_class child ON child.oid = fk.conrelid
            JOIN pg_attribute referencing ON referencing.attrelid = fk.conrelid AND referencing.attnum = fk.conkey[1]
            JOIN pg_attribute referenced ON referenced.attrelid = fk.confrelid AND referenced.attnum = fk.confkey[1]
            WHERE fk.contype = 'f' AND fk.confrelid = to_regclass('users') AND referenced.attname = 'id'
            ORDER BY 1, 2
            SQL);

        return array_map(fn (\stdClass $row): string => $row->table_name.'.'.$row->column_name, array_values($rows));
    }

    /**
     * How many history rows each column holds for this partner; columns with none are left out.
     *
     * @return array<string, int>
     */
    private function historyCounts(int $userId): array
    {
        $counts = array_map(
            fn (string $reference): Builder => $this->historyRows($reference, $userId)->selectRaw('CAST(? AS text) AS reference, COUNT(*) AS total', [$reference]),
            $this->historyColumns(),
        );
        $union = $counts[0];
        foreach (array_slice($counts, 1) as $count) {
            $union->unionAll($count);
        }

        return $union->get()
            ->filter(fn (\stdClass $row): bool => (int) $row->total > 0)
            ->mapWithKeys(fn (\stdClass $row): array => [(string) $row->reference => (int) $row->total])
            ->all();
    }

    private function historyRows(string $reference, int $userId): Builder
    {
        [$table, $column] = explode('.', $reference, 2);
        $rows = DB::table($table)->where($column, $userId);

        return match ($reference) {
            'dealer_applications.user_id' => $rows->where('status', '<>', 'approved'),
            'dealer_ranks.user_id' => $rows->where(fn (Builder $rank) => $rank
                ->where('current_rank', '<>', 'dealer')
                ->orWhere('one_time_incentive_paid', true)
                ->orWhere('monthly_qualified', true)
                ->orWhere('monthly_purchase_total', '<>', 0)
                ->orWhere('lifetime_purchase_total', '<>', 0)),
            'kyc_documents.uploaded_by' => $rows->where('user_id', '<>', $userId),
            'referral_tree_paths.ancestor_id' => $rows->where('descendant_id', '<>', $userId),
            'wallets.user_id' => $rows->where(fn (Builder $wallet) => $wallet
                ->where('available', '<>', 0)
                ->orWhere('held', '<>', 0)
                ->orWhere('commission_held', '<>', 0)),
            default => $rows,
        };
    }

    /**
     * @param  array<string, int>  $history
     * @return array{outcome: string, dealer_code: ?string, records: list<array{key: string, label: string, count: int}>}
     */
    private function outcomeOf(User $partner, array $history): array
    {
        return [
            'outcome' => $history === [] ? self::OutcomeDeleted : self::OutcomeClosed,
            'dealer_code' => $partner->dealer_code,
            'records' => $this->namedRecords($partner->id, $history),
        ];
    }

    /**
     * The history in words for the owner.
     *
     * @param  array<string, int>  $history
     * @return list<array{key: string, label: string, count: int}>
     */
    private function namedRecords(int $userId, array $history): array
    {
        $totals = [];
        foreach ($history as $reference => $count) {
            $key = self::RecordKeys[$reference] ?? 'other';
            // The team tree and sponsor links describe the same people, so they are not added up.
            $totals[$key] = $key === 'people_under' ? max($totals[$key] ?? 0, $count) : ($totals[$key] ?? 0) + $count;
        }
        if (isset($totals['orders'])) {
            $claims = DB::table('payment_claims')->join('orders', 'orders.id', '=', 'payment_claims.order_id')->where('orders.user_id', $userId)->count();
            if ($claims > 0) {
                $totals['payments'] = ($totals['payments'] ?? 0) + $claims;
            }
        }

        $records = [];
        foreach ([...array_unique(array_values(self::RecordKeys)), 'other'] as $key) {
            if (isset($totals[$key])) {
                $records[] = ['key' => $key, 'label' => __('portal.partner_delete_record_'.$key), 'count' => $totals[$key]];
            }
        }

        return $records;
    }

    private function assertCodeMatches(User $partner, string $typedCode): void
    {
        $code = trim((string) $partner->dealer_code);
        if ($code === '' || trim($typedCode) !== $code) {
            throw ValidationException::withMessages(['confirmation' => __('portal.partner_delete_err_code')]);
        }
    }

    private function deleteAccount(User $actor, User $partner, string $reason): void
    {
        $documentPaths = DB::table('kyc_documents')->where('user_id', $partner->id)->pluck('disk_path')
            ->map(fn (mixed $path): string => (string) $path)->values()->all();

        foreach ([...self::DisposableColumns, ...self::PartlyDisposableColumns] as $reference) {
            [$table, $column] = explode('.', $reference, 2);
            DB::table($table)->where($column, $partner->id)->delete();
        }
        DB::table('password_reset_tokens')->whereRaw('LOWER(email) = ?', [mb_strtolower((string) $partner->email)])->delete();
        $partner->pushSubscriptions()->delete();

        Audit::record($actor->id, 'account.deleted', (string) $partner->id, [
            'outcome' => self::OutcomeDeleted,
            'reason' => $reason,
            'role' => $partner->role,
            'dealer_code' => $partner->dealer_code,
            'name' => $partner->name,
        ]);
        $partner->delete();

        DB::afterCommit(function () use ($partner, $documentPaths): void {
            $this->deletePrivateFiles($partner->id, $documentPaths);
            foreach (PortalHost::StaffPortals as $portal) {
                $this->loginCodes->forget($partner, $portal);
            }
        });
    }

    /**
     * @param  array<string, int>  $history
     */
    private function closeAccount(User $actor, User $partner, string $reason, array $history): void
    {
        $changes = [
            'status' => ['before' => $partner->status, 'after' => 'suspended'],
            'directory_visible' => ['before' => (bool) $partner->directory_visible, 'after' => false],
        ];
        $freedPlaceIds = $this->freeSeats($partner);
        $profile = is_array($partner->profile) ? $partner->profile : [];
        if ($freedPlaceIds !== []) {
            $profile[PartnerAccountProvisioning::FreedSeatsKey] = array_values(array_unique([...array_map(intval(...), (array) ($profile[PartnerAccountProvisioning::FreedSeatsKey] ?? [])), ...$freedPlaceIds]));
        }
        $partner->forceFill([
            'status' => 'suspended',
            'directory_visible' => false,
            'closed_at' => $partner->closed_at ?? now(),
            'remember_token' => Str::random(60),
            'profile' => $profile,
        ])->save();
        DB::table('sessions')->where('user_id', $partner->id)->delete();

        Audit::record($actor->id, 'account.closed', (string) $partner->id, [
            'outcome' => self::OutcomeClosed,
            'reason' => $reason,
            'role' => $partner->role,
            'dealer_code' => $partner->dealer_code,
            'freed_places' => PlaceCode::query()->whereKey($freedPlaceIds)->orderBy('code')->pluck('code')->all(),
            'changes' => $changes,
            'records' => $history,
        ]);
    }

    /**
     * A closed dealer holds no place any more, so it can go to someone else.
     *
     * @return list<int> The places given up
     */
    private function freeSeats(User $partner): array
    {
        $seats = AreaDealerAssignment::query()->where('user_id', $partner->id)->where('is_active', true);
        $placeIds = (clone $seats)->whereNotNull('place_code_id')->pluck('place_code_id')->map(fn (mixed $id): int => (int) $id)->all();
        $seats->update(['is_active' => false, 'territory_key' => null, 'updated_at' => now()]);

        return $placeIds;
    }

    /**
     * The same notice and SMS a partner gets when staff suspend the account.
     */
    private function tellPartnerAccountWasClosed(User $partner, string $reason): void
    {
        $fresh = User::find($partner->id);
        if ($fresh === null) {
            return;
        }
        $locale = $fresh->preferredLocale();
        Audit::notice(
            $fresh->id,
            __('portal.notice_account_suspended_title', [], $locale),
            __('portal.notice_account_suspended_body', ['reason' => $reason], $locale),
        );
        $this->sms->send($fresh, 'account.suspended', SmsText::make('sms.account_suspended', ['reason' => $reason]));
    }

    /**
     * ID papers and photos live under folders named after the account.
     *
     * @param  list<string>  $documentPaths
     */
    private function deletePrivateFiles(int $userId, array $documentPaths): void
    {
        Storage::disk('local')->delete($documentPaths);
        Storage::disk('local')->deleteDirectory('kyc-documents/'.$userId);
        Storage::disk('public')->deleteDirectory('partner-photos/'.$userId);
        Storage::disk('public')->deleteDirectory('staff-photos/'.$userId);
    }
}
