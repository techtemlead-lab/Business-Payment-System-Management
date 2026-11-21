<?php

namespace App\Domain\Finance;

use App\Domain\Accounts\PartnerAccountDeletion;
use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\CommissionSettlement;
use App\Domain\Commissions\CommissionWallet;
use App\Models\CommissionAllocation;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Money left in a closed partner account (owner decision, 2026-11-21).
 *
 * The money stays in the account while it is closed: it cannot be withdrawn, and it is there again if the
 * account is opened again. Only the Super Admin may move it to the company, one account at a time, with a
 * reason. The move takes the spendable wallet balance and nothing else (no held commission, no withdrawal on
 * hold), writes a balanced journal (the partner's wallet liability down, company income up), a company profit
 * fund row so Treasury shows it, and an audit entry. A repeated request with the same key changes nothing.
 */
final class ClosedAccountBalance
{
    /** Journal account the moved money is credited to (income; see AccountingStatements::AccountClasses). */
    public const IncomeAccount = 'closed_account_balance_income';

    /** Marks the company profit fund row of a move. */
    public const Source = 'closed_account_balance';

    public const ReasonKey = 'money_core.reason_closed_account_balance';

    public const AuditAction = 'account.closed_balance_to_company';

    /** Closed accounts listed at most on the partner list. */
    private const ListLimit = 50;

    public static function canUse(User $user): bool
    {
        return $user->status === 'active' && $user->role === 'super_admin';
    }

    /**
     * What the owner sees before confirming: is the account closed, and how much would move.
     *
     * @return array{closed: bool, amount: string, can_move: bool}
     */
    public function preview(User $partner): array
    {
        $closed = self::isClosed($partner);
        $amount = $this->available($partner->id);

        return ['closed' => $closed, 'amount' => Money::decimal($amount), 'can_move' => $closed && $amount > 0];
    }

    /**
     * Moves the closed account's spendable balance to the company profit fund.
     *
     * @param  string  $expectedAmount  The amount the owner was shown; a different balance now is refused.
     * @return array{amount: string, allocation_id: int, replayed: bool}
     */
    public function moveToCompany(User $actor, User $partner, string $reason, string $requestKey, string $expectedAmount): array
    {
        abort_unless(self::canUse($actor) && PartnerAccountDeletion::isPartner($partner), 403);
        CommissionWallet::requireActive();

        return DB::transaction(function () use ($actor, $partner, $reason, $requestKey, $expectedAmount): array {
            CommissionWallet::lockPartners($partner->id);
            abort_unless(self::canUse($actor->fresh() ?? $actor), 403);
            $earlier = DB::table('audit_events')->where('action', self::AuditAction)->where('metadata->request_key', $requestKey)->first();
            if ($earlier !== null) {
                $metadata = json_decode((string) $earlier->metadata, true, flags: JSON_THROW_ON_ERROR);
                abort_unless($earlier->subject === (string) $partner->id && (int) $earlier->actor_id === $actor->id, 409, __('money_core.closed_balance_err_key'));

                return ['amount' => (string) $metadata['amount'], 'allocation_id' => (int) $metadata['allocation_id'], 'replayed' => true];
            }
            $locked = User::query()->whereKey($partner->id)->lockForUpdate()->firstOrFail();
            if (! self::isClosed($locked)) {
                throw ValidationException::withMessages(['balance' => __('money_core.closed_balance_err_not_closed')]);
            }
            $wallet = DB::table('wallets')->where('user_id', $locked->id)->lockForUpdate()->first();
            $amount = $wallet ? Money::cents((string) $wallet->available) : 0;
            if ($amount <= 0) {
                throw ValidationException::withMessages(['balance' => __('money_core.closed_balance_err_nothing')]);
            }
            if ($amount !== Money::cents($expectedAmount)) {
                throw ValidationException::withMessages(['balance' => __('money_core.closed_balance_err_changed', ['amount' => Money::grouped(Money::decimal($amount))])]);
            }

            $eventKey = 'closed_account_balance.'.$locked->id.'.'.$requestKey;
            app(CommissionWallet::class)->journal($eventKey, __('money_core.jr_closed_balance', ['code' => (string) $locked->dealer_code]), [
                ['account' => 'wallet_liability', 'user_id' => $locked->id, 'debit' => $amount, 'credit' => 0],
                ['account' => self::IncomeAccount, 'user_id' => null, 'debit' => 0, 'credit' => $amount],
            ]);
            DB::table('wallets')->where('id', $wallet->id)->update(['available' => Money::decimal(Money::cents((string) $wallet->available) - $amount), 'updated_at' => now()]);
            $row = CommissionAllocation::query()->create([
                'order_id' => null,
                'user_id' => null,
                'category' => CommissionSettlement::CompanyProfitCategory,
                'rate_percentage' => '0.00',
                'cash_basis' => Money::decimal($amount),
                'amount' => Money::decimal($amount),
                'original_amount' => Money::decimal($amount),
                'status' => 'reserved',
                'metadata' => [
                    'basis' => 'closed_account_balance',
                    'source' => self::Source,
                    'period_month' => CommissionQualification::currentMonth(),
                    'intended_user_id' => $locked->id,
                    'partner_code' => $locked->dealer_code,
                    'retained_by' => 'company_profit_unclaimed',
                    'reason_key' => self::ReasonKey,
                    'reason' => $reason,
                    'moved_by' => $actor->id,
                    'journal_event_key' => $eventKey,
                ],
            ]);
            Audit::record($actor->id, self::AuditAction, (string) $locked->id, [
                'request_key' => $requestKey,
                'amount' => Money::decimal($amount),
                'reason' => $reason,
                'allocation_id' => $row->id,
                'journal_event_key' => $eventKey,
                'dealer_code' => $locked->dealer_code,
                'closed_at' => $locked->closed_at !== null ? CarbonImmutable::parse($locked->closed_at)->toIso8601String() : null,
            ]);
            $locale = $locked->preferredLocale();
            Audit::notice(
                $locked->id,
                __('money_core.notice_closed_balance_title', [], $locale),
                __('money_core.notice_closed_balance_body', ['amount' => Money::display(Money::decimal($amount), $locale)], $locale),
            );

            return ['amount' => Money::decimal($amount), 'allocation_id' => $row->id, 'replayed' => false];
        }, 3);
    }

    /**
     * Closed dealer or sub-dealer accounts that still hold spendable money, biggest first, for the owner's list.
     *
     * @return array{count: int, total: string, rows: list<array{id: int, name: string, dealer_code: ?string, amount: string, closed_at: ?string}>}
     */
    public function stillHolding(string $role): array
    {
        $query = DB::table('users as u')->join('wallets as w', 'w.user_id', '=', 'u.id')
            ->where('u.role', $role)->whereNotNull('u.closed_at')->where('u.status', '<>', 'active')->where('w.available', '>', 0);
        $totals = (clone $query)->selectRaw('COUNT(*) AS accounts, COALESCE(SUM(w.available), 0) AS total')->first();
        $rows = (clone $query)->orderByDesc('w.available')->orderBy('u.id')->limit(self::ListLimit)
            ->get(['u.id', 'u.name', 'u.dealer_code', 'w.available', 'u.closed_at'])
            ->map(fn (object $row): array => ['id' => (int) $row->id, 'name' => (string) $row->name, 'dealer_code' => $row->dealer_code,
                'amount' => Money::decimal(Money::cents((string) $row->available)), 'closed_at' => $row->closed_at ? (string) $row->closed_at : null])
            ->values()->all();

        return ['count' => (int) ($totals->accounts ?? 0), 'total' => Money::decimal(Money::cents((string) ($totals->total ?? '0'))), 'rows' => $rows];
    }

    public static function isClosed(User $partner): bool
    {
        return $partner->closed_at !== null && $partner->status !== 'active';
    }

    private function available(int $userId): int
    {
        $available = DB::table('wallets')->where('user_id', $userId)->value('available');

        return $available === null ? 0 : Money::cents((string) $available);
    }
}
