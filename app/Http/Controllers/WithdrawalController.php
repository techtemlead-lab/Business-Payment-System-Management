<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Notifications\SmsText;
use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PartnerPayoutAccounts;
use App\Domain\Payouts\PayoutLimits;
use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Payouts\PayoutReturns;
use App\Models\User;
use App\Support\Audit;
use App\Support\LocalDigits;
use App\Support\ReasonFields;
use App\Support\TransactionReference;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawalController extends Controller
{
    /** Amounts as typed: up to the size of the money column (12 digits of taka), with paisa. */
    public const AmountPattern = '/^\d{1,12}(\.\d{1,2})?$/D';

    public function store(Request $r, ManualPayoutService $service): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($r->user()->role, ['dealer', 'sub_dealer'], true), 403);
        CommissionWallet::requireActive();
        // V3: a partner with saved payout accounts picks one; its channel and number are read from the saved record, never typed.
        $saved = app(PartnerPayoutAccounts::class);
        $hasSaved = $saved->listFor($r->user()) !== [];
        $data = $r->validate(['amount' => ['required', 'regex:'.self::AmountPattern], 'payout_account_id' => [$hasSaved ? 'required' : 'nullable', 'integer'],
            'channel' => [$hasSaved ? 'nullable' : 'required', 'in:'.implode(',', PaymentAccounts::channels())],
            'destination' => [$hasSaved ? 'nullable' : 'required', 'string', 'min:6', 'max:200'], 'idempotency_key' => 'required|uuid'], ['amount.regex' => __('payouts.err_amount_digits')]);
        if ($hasSaved) {
            $data = [...$data, ...$saved->asWithdrawal($r->user(), (int) $data['payout_account_id'])];
        }
        ['id' => $id, 'created' => $created] = $service->submit($r->user(), $data);
        // A replayed request (same idempotency key) already sent its SMS the first time.
        if ($created) {
            $user = $r->user();
            app(PartnerEventSms::class)->send(
                $user,
                'withdrawal.requested',
                SmsText::make('sms.withdrawal_requested', [
                    'amount' => $data['amount'],
                    'channel' => $data['channel'],
                ]),
            );
        }
        if ($r->expectsJson()) {
            return response()->json(['data' => ['id' => $id, 'status' => 'requested']], 201);
        }

        return redirect('/withdrawals/'.$id)->with('success', __('portal.flash_withdrawal_requested'));
    }

    public function show(Request $r, string $id, ManualPayoutService $service): Response
    {
        $w = DB::table('withdrawals')->where('id', $id)->first();
        $finance = $r->user()->allows('finance');
        abort_unless($w && ($w->user_id === $r->user()->id || $finance), 404);
        $destination = decrypt($w->destination);
        if ($finance) {
            Audit::record($r->user()->id, 'withdrawal.destination_viewed', $id);
        }

        return Inertia::render('WithdrawalDetail', ['withdrawal' => collect((array) $w)->except(['receipt_path', 'receipt_sha256', 'destination', 'idempotency_key', ...($finance ? [] : ['return_note'])])->all() + ['destination_masked' => str_repeat('•', max(0, strlen($destination) - 4)).substr($destination, -4), 'destination_for_payment' => $finance ? $destination : null, 'has_receipt' => (bool) $w->receipt_path, 'payer_state' => $finance ? $service->payerState($w, $r->user()) : null, 'waits_for_owner' => $finance && $service->waitsForOwner($w, $r->user()), 'return_state' => $finance ? app(PayoutReturns::class)->state($w, $r->user()) : null, ...$this->timing($w)],
            'paymentAccounts' => $finance ? app(PaymentAccounts::class)->listing($r->user()) : [],
            'payoutReceipt' => app(PayoutReceipts::class)->forWithdrawal($id),
            'facts' => $finance && in_array($w->status, ['requested', 'approved'], true) ? $service->reviewFacts($r->user(), $id) : null,
            'events' => DB::table('withdrawal_events')->join('users', 'users.id', '=', 'actor_id')->where('withdrawal_id', $id)->orderBy('withdrawal_events.id')->get(['event', 'note', 'withdrawal_events.created_at', 'users.name'])]);
    }

    /** The facts finance checks in the review and payment boxes on the payouts desk (MA-07). */
    public function facts(Request $r, string $id, ManualPayoutService $service): JsonResponse
    {
        abort_unless($r->user()->allows('finance'), 403);

        return response()->json(['data' => $service->reviewFacts($r->user(), $id)]);
    }

    public function review(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => ReasonFields::rule('money.withdrawal_review', 1000), 'payer_id' => 'nullable|integer']);
        $s->review($r->user(), $id, $d['decision'], ReasonFields::text($d['reason'] ?? null), isset($d['payer_id']) ? (int) $d['payer_id'] : null);
        $this->notifyPartnerWithdrawal($id, $d['decision']);

        return back()->with('success', __('portal.flash_withdrawal_review'));
    }

    /** The partner takes back their own withdrawal while nobody has approved it yet. */
    public function cancel(Request $r, string $id, ManualPayoutService $s): JsonResponse|RedirectResponse
    {
        $s->cancel($r->user(), $id);
        if ($r->expectsJson()) {
            return response()->json(['data' => ['id' => $id, 'status' => 'cancelled']]);
        }

        return back()->with('success', __('payouts.flash_cancelled'));
    }

    /** Names who pays an approved withdrawal; "take it over" sends the viewer's own id. */
    public function payer(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['payer_id' => 'required|integer']);
        $s->assignPayer($r->user(), $id, (int) $d['payer_id']);

        return back()->with('success', __('payouts.flash_payer_named'));
    }

    public function settle(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        TransactionReference::tidy($r, 'payment_reference');
        // Owner decision: every manual payout records how it was paid, and a numbered receipt is issued.
        // The proof may follow later ("paid, receipt later") so a payer without the file at hand is not stopped.
        $d = $r->validate([...PayoutReceipts::rules(), 'payment_account_id' => 'nullable|integer|exists:payment_accounts,id', 'receipt_later' => 'sometimes|boolean',
            'receipt' => ['nullable', Rule::requiredIf(fn (): bool => $r->input('method') !== 'cash' && ! $r->boolean('receipt_later')), 'file', 'mimes:pdf,jpg,jpeg,png', 'max:5120'], 'paid_at' => ['required', 'date', function ($attribute, $value, $fail) {
                if (Carbon::parse($value, 'Asia/Dhaka')->isFuture()) {
                    $fail(__('portal.err_wd_paid_at_future'));
                }
            }], 'note' => ReasonFields::rule('money.withdrawal_settle', 1000)], TransactionReference::messages('payment_reference'));
        $reference = isset($d['payment_reference']) ? strtoupper(trim($d['payment_reference'])) : null;
        $number = $s->settle($r->user(), $id, $d, $r->file('receipt'), $reference, Carbon::parse($d['paid_at'], 'Asia/Dhaka')->utc()->toDateTimeString(), ReasonFields::text($d['note'] ?? null), isset($d['payment_account_id']) ? (int) $d['payment_account_id'] : null, $r->boolean('receipt_later'));
        $this->notifyPartnerWithdrawal($id, 'paid');

        return back()->with('success', __('portal.flash_withdrawal_paid_receipt', ['number' => $number]));
    }

    /** Attaches the transfer proof to a payout recorded as "paid, receipt later". */
    public function attachReceipt(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $s->attachReceipt($r->user(), $id, $d['receipt']);

        return back()->with('success', __('payouts.flash_receipt_attached'));
    }

    /** "Money came back" on a paid withdrawal: the first person records it (the owner finishes alone). */
    public function recordReturn(Request $r, string $id, PayoutReturns $returns): JsonResponse|RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['note' => 'required|string|min:5|max:1000']);
        $done = $returns->request($r->user(), $id, ReasonFields::text($d['note']));
        if ($r->expectsJson()) {
            return response()->json(['data' => ['id' => $id, 'status' => $done ? PayoutReturns::Status : 'paid', 'waiting_second' => ! $done]]);
        }

        return back()->with('success', __($done ? 'payouts.flash_returned' : 'payouts.flash_return_recorded'));
    }

    /** A second finance person confirms the money came back; it goes to the partner's wallet. */
    public function confirmReturn(Request $r, string $id, PayoutReturns $returns): JsonResponse|RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['note' => 'required|string|min:5|max:1000']);
        $returns->confirm($r->user(), $id, ReasonFields::text($d['note']));

        return $r->expectsJson() ? response()->json(['data' => ['id' => $id, 'status' => PayoutReturns::Status]]) : back()->with('success', __('payouts.flash_returned'));
    }

    /** Takes back a "money came back" step nobody has confirmed. */
    public function cancelReturn(Request $r, string $id, PayoutReturns $returns): JsonResponse|RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['note' => 'required|string|min:5|max:1000']);
        $returns->cancel($r->user(), $id, ReasonFields::text($d['note']));

        return $r->expectsJson() ? response()->json(['data' => ['id' => $id, 'status' => 'paid']]) : back()->with('success', __('payouts.flash_return_cancelled'));
    }

    /** A partner saves their tax number (e-TIN, 12 digits) for the payout tax register (D12). */
    public function tin(Request $r): RedirectResponse
    {
        abort_unless(in_array($r->user()->role, ['dealer', 'sub_dealer'], true), 403);
        $r->merge(['tin' => preg_replace('/\D+/', '', LocalDigits::latin((string) $r->input('tin')))]);
        $d = $r->validate(['tin' => ['nullable', 'regex:/^\d{12}$/D']], ['tin.regex' => __('payouts.err_tin_format')]);
        $user = User::findOrFail($r->user()->id);
        $user->forceFill(['tin' => ($d['tin'] ?? '') === '' ? null : $d['tin']])->save();
        Audit::record($user->id, 'account.tin_saved', (string) $user->id, ['has_tin' => $user->tin !== null]);

        return back()->with('success', __('payouts.flash_tin_saved'));
    }

    /** Owners only: per-method withdrawal limits, the office cash limit and the payout due days. */
    public function limits(Request $r, PayoutLimits $limits): JsonResponse|RedirectResponse
    {
        $amount = ['nullable', 'regex:'.self::AmountPattern];
        $d = $r->validate([
            'per_channel' => 'array',
            ...array_fill_keys(array_map(fn (string $channel): string => 'per_channel.'.$channel, PaymentAccounts::channels()), $amount),
            'office_cash' => ['required', 'regex:'.self::AmountPattern],
            'due_days' => 'required|integer|min:1|max:'.PayoutLimits::MaximumDueDays,
            'version' => 'required|integer|min:0',
            'reason' => ReasonFields::rule('money.withdrawal_minimum', 1000, 5),
        ], ['*.regex' => __('payouts.err_amount_digits'), 'per_channel.*.regex' => __('payouts.err_amount_digits')]);
        $limits->save($r->user(), ['per_channel' => $d['per_channel'] ?? [], 'office_cash' => $d['office_cash'], 'due_days' => (int) $d['due_days']], (int) $d['version'], ReasonFields::text($d['reason'] ?? null));

        return $r->expectsJson() ? response()->json(['data' => $limits->settings()]) : back()->with('success', __('portal.flash_changes_recorded'));
    }

    /**
     * How long the request has waited, who pays it and by when (MA-06).
     *
     * @return array<string, mixed>
     */
    private function timing(object $w): array
    {
        $dueDays = app(PayoutLimits::class)->dueDays();
        $approved = $w->approved_at ? CarbonImmutable::parse($w->approved_at) : null;
        $since = $w->status === 'approved' && $approved ? $approved : CarbonImmutable::parse($w->created_at);

        return [
            'waiting_days' => in_array($w->status, ['requested', 'approved'], true) ? (int) $since->diffInDays(now()) : null,
            'pay_by' => $w->status === 'approved' && $approved ? $approved->addDays($dueDays)->toIso8601String() : null,
            'overdue' => $w->status === 'approved' && $approved !== null && $approved->addDays($dueDays)->isPast(),
            'payer_name' => $w->payer_id ? User::whereKey($w->payer_id)->value('name') : null,
            'on_hold' => $w->on_hold_at !== null,
            'can_cancel' => $w->status === 'requested',
        ];
    }

    private function notifyPartnerWithdrawal(string $id, string $event): void
    {
        $withdrawal = DB::table('withdrawals')->where('id', $id)->first();
        if (! $withdrawal) {
            return;
        }
        $partner = User::find($withdrawal->user_id);
        if (! $partner) {
            return;
        }
        $template = match ($event) {
            'approved' => 'sms.withdrawal_approved',
            'rejected' => 'sms.withdrawal_rejected',
            'paid' => 'sms.withdrawal_paid',
            default => null,
        };
        if ($template === null) {
            return;
        }
        app(PartnerEventSms::class)->send(
            $partner,
            'withdrawal.'.$event,
            SmsText::make($template, [
                'amount' => $withdrawal->amount,
                'channel' => $withdrawal->channel,
            ]),
        );
    }

    public function receipt(Request $r, string $id): StreamedResponse
    {
        $w = DB::table('withdrawals')->where('id', $id)->first();
        abort_unless($w && ($w->user_id === $r->user()->id || $r->user()->allows('finance')), 404);
        abort_unless($w->receipt_path && Storage::disk('local')->exists($w->receipt_path), 404);
        Audit::record($r->user()->id, 'receipt.downloaded', $id);
        $ext = match ($w->receipt_mime) {
            'application/pdf' => 'pdf','image/png' => 'png',default => 'jpg'
        };

        return Storage::disk('local')->download($w->receipt_path, 'payment-receipt-'.$id.'.'.$ext, ['Content-Type' => $w->receipt_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
