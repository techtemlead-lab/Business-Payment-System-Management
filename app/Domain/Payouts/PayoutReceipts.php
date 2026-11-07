<?php

namespace App\Domain\Payouts;

use App\Models\User;
use App\Support\AmountInWords;
use App\Support\Audit;
use App\Support\Money;
use App\Support\SiteCustomization;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Numbered receipts for commission payouts paid by hand. Owner decision: all commissions
 * are paid manually for now; staff record how the money went (cash, bank, bKash, Nagad or
 * other) and the dealer or sub-dealer keeps an online copy of the receipt in their portal.
 */
final class PayoutReceipts
{
    /** @var list<string> */
    public const Methods = ['cash', 'bank', 'bkash', 'nagad', 'other'];

    /**
     * What staff must enter for each method: a transaction reference for anything but cash,
     * the bank name and account for a bank transfer, the wallet number for bKash or Nagad,
     * and the name of the person who took the money for cash.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'method' => 'required|in:'.implode(',', self::Methods),
            'payment_reference' => 'nullable|required_unless:method,cash|string|max:100',
            'bank_name' => 'nullable|required_if:method,bank|string|max:100',
            'account_number' => 'nullable|required_if:method,bank|string|max:60',
            'wallet_number' => ['nullable', 'required_if:method,bkash,nagad', 'regex:/^\+?[0-9][0-9 \-]{5,19}$/D'],
            'received_by' => 'nullable|required_if:method,cash|string|max:100',
        ];
    }

    /**
     * Writes the receipt for a withdrawal being marked paid. Call it inside the payout
     * transaction so the receipt and the payment are saved together.
     *
     * @param  array{method: string, bank_name?: ?string, account_number?: ?string, wallet_number?: ?string, received_by?: ?string}  $payout
     * @return array{id: int, number: string}
     */
    public function issue(User $actor, object $withdrawal, array $payout, ?string $reference, string $paidAt): array
    {
        $method = $payout['method'];
        abort_unless(in_array($method, self::Methods, true), 422);
        $account = match ($method) {
            'bank', 'other' => $this->clean($payout['account_number'] ?? null),
            'bkash', 'nagad' => $this->clean($payout['wallet_number'] ?? null),
            default => null,
        };
        // The number comes from the table's own sequence, so receipt numbers only go up and never repeat.
        $id = (int) DB::selectOne("SELECT nextval(pg_get_serial_sequence('payout_receipts', 'id')) AS id")->id;
        $number = 'MN-PR-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
        $amountCents = Money::cents((string) $withdrawal->amount);
        $withheldCents = Money::cents((string) ($withdrawal->withheld_amount ?? '0'));
        $netCents = Money::cents((string) ($withdrawal->net_amount ?? $withdrawal->amount));
        DB::table('payout_receipts')->insert([
            'id' => $id,
            'number' => $number,
            'withdrawal_id' => $withdrawal->id,
            'user_id' => $withdrawal->user_id,
            'amount' => Money::decimal($amountCents),
            'withheld_amount' => Money::decimal($withheldCents),
            'net_amount' => Money::decimal($netCents),
            'method' => $method,
            'reference' => $reference,
            'bank_name' => in_array($method, ['bank', 'other'], true) ? $this->clean($payout['bank_name'] ?? null) : null,
            'account_number' => $account !== null ? encrypt($account) : null,
            'received_by' => $method === 'cash' ? $this->clean($payout['received_by'] ?? null) : null,
            'paid_at' => $paidAt,
            'paid_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Audit::record($actor->id, 'payout_receipt.issued', $number, [
            'receipt_id' => $id, 'withdrawal_id' => $withdrawal->id, 'user_id' => $withdrawal->user_id,
            'method' => $method, 'reference' => $reference, 'net_amount' => Money::decimal($netCents), 'paid_at' => $paidAt,
        ]);

        return ['id' => $id, 'number' => $number];
    }

    /** The partner's own receipts, newest first. */
    public function forPartner(User $partner, int $perPage = 20): LengthAwarePaginator
    {
        return DB::table('payout_receipts')
            ->where('user_id', $partner->id)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->select(['id', 'number', 'withdrawal_id', 'amount', 'withheld_amount', 'net_amount', 'method', 'reference', 'paid_at'])
            ->paginate($perPage)
            ->withQueryString();
    }

    /** The receipt for one withdrawal, if it has been paid. */
    public function forWithdrawal(string $withdrawalId): ?object
    {
        return DB::table('payout_receipts')->where('withdrawal_id', $withdrawalId)->first(['id', 'number', 'method', 'reference', 'bank_name', 'received_by']);
    }

    /**
     * Owners and finance staff see every receipt; a dealer or sub-dealer sees only their own.
     * Anyone else gets "not found", so receipt numbers cannot be probed.
     */
    public function assertVisible(User $actor, object $receipt): void
    {
        if ($actor->owner() || $actor->allows('finance')) {
            return;
        }
        abort_unless(in_array($actor->role, ['dealer', 'sub_dealer'], true) && (int) $receipt->user_id === (int) $actor->id, 404);
    }

    /** The printable receipt page (A4 or an 80 mm receipt printer). */
    public function printHtml(User $actor, int $id): Response
    {
        $receipt = DB::table('payout_receipts')->where('id', $id)->first();
        abort_unless($receipt !== null, 404);
        $this->assertVisible($actor, $receipt);
        Audit::record($actor->id, 'payout_receipt.viewed', (string) $receipt->number);

        return response()->view('receipts.payout-receipt', $this->payload($actor, $receipt));
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(User $actor, object $receipt): array
    {
        $partner = User::query()->find($receipt->user_id);
        $payer = User::query()->find($receipt->paid_by);
        $custom = SiteCustomization::all();
        $paidAt = CarbonImmutable::parse((string) $receipt->paid_at, 'UTC')->timezone((string) config('madina.business_timezone', 'Asia/Dhaka'));
        $account = $receipt->account_number ? (string) decrypt($receipt->account_number) : null;
        $isPartnerCopy = (int) $actor->id === (int) $receipt->user_id;

        return [
            'company' => [
                'name' => (string) ($custom['contact_company_title'] ?? config('madina.company_name', 'Madina Natural Health Care')),
                'address' => (string) ($custom['office_address'] ?? $custom['footer_address'] ?? config('madina.company_address')),
                'phones' => (string) ($custom['contact_phone'] ?? $custom['footer_phone'] ?? config('madina.company_phone')),
                'logo_url' => is_file(public_path('branding/madina-logo-latest.png')) ? asset('branding/madina-logo-latest.png') : asset('madina-header-logo.png'),
            ],
            'receipt' => [
                'id' => (int) $receipt->id,
                'number' => (string) $receipt->number,
                'paid_on' => $paidAt->format('d/m/Y'),
                'paid_time' => $paidAt->format('h:i A'),
                'amount' => Money::grouped((string) $receipt->amount),
                'withheld' => Money::grouped((string) $receipt->withheld_amount),
                'has_withholding' => Money::cents((string) $receipt->withheld_amount) > 0,
                'net' => Money::grouped((string) $receipt->net_amount),
                'net_in_words' => AmountInWords::taka((string) $receipt->net_amount),
                'method' => __('portal.payout_method_'.$receipt->method),
                'is_cash' => $receipt->method === 'cash',
                'reference' => $receipt->reference,
                'bank_name' => $receipt->bank_name,
                'account_masked' => $account !== null ? $this->masked($account) : null,
                'account_label' => in_array($receipt->method, ['bkash', 'nagad'], true) ? __('portal.prc_wallet_number') : __('portal.prc_account_number'),
                'received_by' => $receipt->received_by,
                'withdrawal_ref' => strtoupper(substr((string) $receipt->withdrawal_id, 0, 8)),
            ],
            'partner' => [
                'name' => $partner?->name,
                'code' => $partner?->dealer_code ?: '—',
                'designation' => $partner?->role === 'sub_dealer' ? __('portal.inv_role_sub_dealer') : __('portal.inv_role_dealer'),
                'phone' => $partner?->phone ?: '—',
            ],
            'paid_by' => $payer?->name ?? '—',
            'copy_label' => $isPartnerCopy ? __('portal.prc_partner_copy') : __('portal.prc_office_copy'),
            'csp_nonce' => (string) request()->attributes->get('csp_nonce', ''),
        ];
    }

    private function masked(string $value): string
    {
        $value = trim($value);

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    private function clean(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
