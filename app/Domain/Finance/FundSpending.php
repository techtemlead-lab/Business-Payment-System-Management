<?php

namespace App\Domain\Finance;

use App\Domain\Clinic\ClinicDispensing;
use App\Domain\Commissions\CommissionWallet;
use App\Domain\Security\SelfApproval;
use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Taking money out of a company fund: one person records the spend, a different person
 * checks it and only then does the balance drop and the journal post. Spends live in
 * reserve_disbursements (program company_spend) so they share its receipts and money lock.
 *
 * The balance can never be spent below zero. A below-cost clinic sale can still push the
 * clinic fund negative, because that is recorded by the sale itself, not by this flow.
 */
final class FundSpending
{
    public const Program = 'company_spend';

    /** Ledger accounts these funds already post to, so a spend draws down the same account. */
    private const FundAccounts = [
        'company_share' => ClinicDispensing::ManufacturerAccount,
        'clinic' => ClinicDispensing::ClinicFundAccount,
        'marketing' => 'company_marketing_expense',
    ];

    public function __construct(private CommissionWallet $wallets, private CompanyFunds $funds) {}

    /** Owners and finance staff only; the owners' own fund is the owners' alone. */
    public static function canManage(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('finance'));
    }

    /**
     * @param  array{fund: string, expense_head_id: int, amount: string, spent_on: string, payee: string, payment_method: string, reference?: ?string, note?: ?string, request_key: string}  $data
     */
    public function request(User $actor, array $data, ?UploadedFile $receipt): int
    {
        $this->assertMayUseFund($actor, $data['fund']);
        $reference = $this->reference($data['reference'] ?? null);
        $path = $receipt?->storeAs('fund-spend-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        try {
            return DB::transaction(function () use ($actor, $data, $receipt, $reference, $path): int {
                CommissionWallet::lock();
                abort_unless(self::canManage($actor->fresh()), 403);
                $old = DB::table('reserve_disbursements')->where('request_key', $data['request_key'])->first();
                if ($old) {
                    abort_if($old->program !== self::Program || $old->fund_key !== $data['fund'] || Money::cents($old->amount) !== Money::cents($data['amount'])
                        || (int) $old->expense_head_id !== (int) $data['expense_head_id'] || $old->created_by !== $actor->id, 409, __('funds.err_repeat_key'));
                    if ($path !== null) {
                        Storage::disk('local')->delete($path);
                    }

                    return (int) $old->id;
                }
                $head = DB::table('fund_expense_heads')->where('id', $data['expense_head_id'])->where('is_active', true)->first();
                if (! $head) {
                    throw ValidationException::withMessages(['expense_head_id' => __('funds.err_head_inactive')]);
                }
                if ($data['fund'] === 'tour' && ! $head->for_tours) {
                    throw ValidationException::withMessages(['expense_head_id' => __('funds.err_tour_head')]);
                }
                $this->assertReferenceFree($reference);
                if (Money::cents($data['amount']) > $this->funds->balances()[$data['fund']]['available']) {
                    throw ValidationException::withMessages(['amount' => __('funds.err_overspend')]);
                }
                $id = DB::table('reserve_disbursements')->insertGetId([
                    'program' => self::Program,
                    'fund_key' => $data['fund'],
                    'expense_head_id' => $data['expense_head_id'],
                    'payee_name' => $data['payee'],
                    'amount' => $data['amount'],
                    'spent_on' => $data['spent_on'],
                    'payment_method' => $data['payment_method'],
                    'payment_reference' => $reference,
                    'request_key' => $data['request_key'],
                    'reason' => (string) ($data['note'] ?? ''),
                    'receipt_path' => $path,
                    'receipt_hash' => $receipt ? hash_file('sha256', $receipt->getRealPath()) : null,
                    'created_by' => $actor->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record($actor->id, 'fund.spend_requested', (string) $id, ['fund' => $data['fund'], 'amount' => $data['amount'], 'head' => $head->name_en]);

                return $id;
            }, 3);
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
    }

    /**
     * Approves or rejects a spend. The owner decides a spend they recorded themselves, and may
     * add its receipt while doing so.
     */
    public function review(User $actor, int $id, string $decision, string $reason, ?UploadedFile $receipt = null): void
    {
        abort_unless(self::canManage($actor), 403);
        if ($decision === 'approved') {
            CommissionWallet::requireActive();
        }
        $path = $receipt?->storeAs('fund-spend-receipts', Str::uuid().'.'.$receipt->guessExtension(), 'local');
        $attached = false;
        try {
            $this->decide($actor, $id, $decision, $reason, $receipt, $path, $attached);
        } catch (\Throwable $exception) {
            if ($path !== null) {
                Storage::disk('local')->delete($path);
            }
            throw $exception;
        }
        if ($path !== null && ! $attached) {
            Storage::disk('local')->delete($path);
        }
    }

    /**
     * Whoever recorded a spend may withdraw it before anyone has reviewed it.
     */
    public function cancel(User $actor, int $id, string $reason): void
    {
        abort_unless(self::canManage($actor), 403);
        DB::transaction(function () use ($actor, $id, $reason): void {
            CommissionWallet::lock();
            abort_unless(self::canManage($actor->fresh()), 403);
            $row = DB::table('reserve_disbursements')->where('id', $id)->where('program', self::Program)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            abort_unless(! in_array($row->fund_key, CompanyFunds::OwnerOnly, true) || $actor->owner(), 403);
            if ($row->status !== 'requested' || $row->created_by !== $actor->id) {
                throw ValidationException::withMessages(['spend' => __('self_approval.err_cancel_spend')]);
            }
            DB::table('reserve_disbursements')->where('id', $id)->update(['status' => 'cancelled', 'updated_at' => now()]);
            $this->freeReference($id);
            Audit::record($actor->id, 'fund.spend_cancelled', (string) $id, ['fund' => $row->fund_key, 'amount' => (string) $row->amount, 'reference' => $row->payment_reference, 'reason' => $reason]);
        }, 3);
    }

    /**
     * Who may approve a spend from this fund: owners and finance approvers, or only
     * owners for the owner-only fund.
     *
     * @return \Closure(User): bool
     */
    public static function approverOf(string $fund): \Closure
    {
        return fn (User $person): bool => $person->owner() || (! in_array($fund, CompanyFunds::OwnerOnly, true) && $person->canAct('finance', 'approve'));
    }

    private function decide(User $actor, int $id, string $decision, string $reason, ?UploadedFile $receipt, ?string $path, bool &$attached): void
    {
        DB::transaction(function () use ($actor, $id, $decision, $reason, $receipt, $path, &$attached): void {
            CommissionWallet::lock();
            abort_unless(self::canManage($actor->fresh()), 403);
            $row = DB::table('reserve_disbursements')->where('id', $id)->where('program', self::Program)->lockForUpdate()->first();
            abort_unless($row !== null, 404);
            $this->assertMayUseFund($actor, $row->fund_key);
            if ($row->status !== 'requested') {
                throw ValidationException::withMessages(['spend' => __('funds.err_already_reviewed')]);
            }
            $selfApproved = $row->created_by === $actor->id;
            if ($selfApproved) {
                app(SelfApproval::class)->finishOwnStep($actor, 'spend', __('funds.err_own_spend'));
                if ($row->receipt_path === null && $path !== null) {
                    DB::table('reserve_disbursements')->where('id', $id)->update(['receipt_path' => $path, 'receipt_hash' => hash_file('sha256', $receipt->getRealPath())]);
                    $attached = true;
                }
            }
            if ($decision === 'approved') {
                $this->post($actor, $row);
            }
            DB::table('reserve_disbursements')->where('id', $id)->update([
                'status' => $decision === 'approved' ? 'paid' : 'rejected',
                'approved_by' => $actor->id,
                'paid_by' => $decision === 'approved' ? $actor->id : null,
                'paid_at' => $decision === 'approved' ? now() : null,
                'updated_at' => now(),
            ]);
            if ($decision === 'rejected') {
                $this->freeReference($id);
            }
            Audit::record($actor->id, $decision === 'approved' ? 'fund.spend_approved' : 'fund.spend_rejected', (string) $id, ['fund' => $row->fund_key, 'amount' => (string) $row->amount, 'reference' => $row->payment_reference, 'reason' => $reason, ...($selfApproved ? ['self_approved' => true] : [])]);
        }, 3);
    }

    /**
     * @param  array{name_en: string, name_bn: string, for_tours?: bool}  $data
     */
    public function createHead(User $actor, array $data): int
    {
        abort_unless(self::canManage($actor), 403);
        $id = DB::table('fund_expense_heads')->insertGetId([
            'name_en' => $data['name_en'],
            'name_bn' => $data['name_bn'],
            'for_tours' => (bool) ($data['for_tours'] ?? false),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Audit::record($actor->id, 'fund.head_created', (string) $id, ['name_en' => $data['name_en']]);

        return $id;
    }

    /**
     * @param  array{name_en: string, name_bn: string, is_active: bool, for_tours?: bool}  $data
     */
    public function updateHead(User $actor, int $id, array $data): void
    {
        abort_unless(self::canManage($actor), 403);
        $before = DB::table('fund_expense_heads')->where('id', $id)->first();
        abort_unless($before !== null, 404);
        DB::table('fund_expense_heads')->where('id', $id)->update([
            'name_en' => $data['name_en'],
            'name_bn' => $data['name_bn'],
            'is_active' => $data['is_active'],
            'for_tours' => (bool) ($data['for_tours'] ?? $before->for_tours),
            'updated_at' => now(),
        ]);
        Audit::record($actor->id, 'fund.head_updated', (string) $id, ['before' => (array) $before, 'after' => $data]);
    }

    /** Takes the money out of the fund and writes the balanced journal. Runs under the money lock. */
    private function post(User $actor, object $row): void
    {
        $amount = Money::cents((string) $row->amount);
        if (in_array($row->fund_key, CompanyFunds::AllocationFunds, true)) {
            $this->drawFromAllocations($row, $amount);
        } elseif ($amount > $this->funds->balances()[$row->fund_key]['balance']) {
            throw ValidationException::withMessages(['spend' => __('funds.err_overspend')]);
        }
        $this->wallets->journal('fund.spend.'.$row->id, __('funds.jr_spend', ['fund' => __('funds.fund_'.$row->fund_key), 'head' => $this->headName((int) $row->expense_head_id)]), [
            ['account' => self::FundAccounts[$row->fund_key] ?? 'company_fund_expense', 'user_id' => null, 'debit' => $amount, 'credit' => 0],
            ['account' => $row->fund_key === 'clinic' ? ClinicDispensing::ClinicCashAccount : 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => $amount],
        ]);
    }

    /** Draws the amount oldest-first from the fund's allocation rows, as the marketing flow does. */
    private function drawFromAllocations(object $row, int $amount): void
    {
        $left = $amount;
        $sources = [];
        foreach ($this->funds->rowsWithMoney($row->fund_key) as $fund) {
            $take = min($left, Money::cents((string) $fund->remaining));
            if ($take <= 0) {
                continue;
            }
            $sources[] = ['allocation_id' => $fund->id, 'order_id' => $fund->order_id, 'amount' => $take];
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }
        if ($left > 0) {
            throw ValidationException::withMessages(['spend' => __('funds.err_overspend')]);
        }
        $refunded = DB::table('commission_batches')->whereIn('order_id', array_values(array_unique(array_filter(array_column($sources, 'order_id')))))->pluck('refunded_amount', 'order_id');
        foreach (array_chunk($sources, 1000) as $chunk) {
            DB::table('reserve_expense_sources')->insert(array_map(fn (array $source): array => [
                'disbursement_id' => $row->id,
                'allocation_id' => $source['allocation_id'],
                'amount' => Money::decimal($source['amount']),
                'refunded_basis' => $refunded[$source['order_id']] ?? '0.00',
                'created_at' => now(),
                'updated_at' => now(),
            ], $chunk));
        }
    }

    private function assertMayUseFund(User $actor, string $fund): void
    {
        abort_unless(self::canManage($actor), 403);
        abort_unless(! in_array($fund, CompanyFunds::OwnerOnly, true) || $actor->owner(), 403);
        if (! CompanyFunds::isSpendable($fund)) {
            throw ValidationException::withMessages(['fund' => __('funds.err_award_only')]);
        }
    }

    private function assertReferenceFree(?string $reference): void
    {
        if ($reference !== null && DB::table('reserve_disbursements')->where('payment_reference', $reference)->exists()) {
            throw ValidationException::withMessages(['reference' => __('funds.err_reference_used')]);
        }
    }

    /** A withdrawn or refused spend never happened, so its bank reference is free for the corrected entry. The audit log keeps it. */
    private function freeReference(int $id): void
    {
        DB::table('reserve_disbursements')->where('id', $id)->update(['payment_reference' => null]);
    }

    private function reference(?string $reference): ?string
    {
        $reference = trim((string) $reference);

        return $reference === '' ? null : Str::upper($reference);
    }

    private function headName(int $id): string
    {
        $head = DB::table('fund_expense_heads')->where('id', $id)->first();

        return (string) (app()->getLocale() === 'bn' ? $head?->name_bn : $head?->name_en);
    }
}
