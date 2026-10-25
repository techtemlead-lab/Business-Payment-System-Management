<?php

namespace App\Http\Controllers;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\PaymentAccounts;
use App\Domain\Notifications\PartnerEventSms;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use App\Support\Audit;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WithdrawalController extends Controller
{
    public function store(Request $r, ManualPayoutService $service): JsonResponse|RedirectResponse
    {
        abort_unless(in_array($r->user()->role, ['dealer', 'sub_dealer'], true), 403);
        CommissionWallet::requireActive();
        $data = $r->validate(['amount' => ['required', 'regex:/^\d{1,8}(\.\d{1,2})?$/'], 'channel' => 'required|in:'.implode(',', PaymentAccounts::channels()),
            'destination' => 'required|string|min:6|max:200', 'idempotency_key' => 'required|uuid']);
        $id = $service->request($r->user(), $data);
        $user = $r->user();
        app(PartnerEventSms::class)->send(
            $user,
            'withdrawal.requested',
            __('sms.withdrawal_requested', [
                'amount' => $data['amount'],
                'channel' => $data['channel'],
            ], $user->preferredLocale()),
        );
        if ($r->expectsJson()) {
            return response()->json(['data' => ['id' => $id, 'status' => 'requested']], 201);
        }

        return redirect('/withdrawals/'.$id)->with('success', __('portal.flash_withdrawal_requested'));
    }

    public function show(Request $r, string $id): Response
    {
        $w = DB::table('withdrawals')->where('id', $id)->first();
        abort_unless($w && ($w->user_id === $r->user()->id || $r->user()->allows('finance')), 404);
        $destination = decrypt($w->destination);
        if ($r->user()->allows('finance')) {
            Audit::record($r->user()->id, 'withdrawal.destination_viewed', $id);
        }

        return Inertia::render('WithdrawalDetail', ['withdrawal' => collect((array) $w)->except(['receipt_path', 'receipt_sha256', 'destination', 'idempotency_key'])->all() + ['destination_masked' => str_repeat('•', max(0, strlen($destination) - 4)).substr($destination, -4), 'destination_for_payment' => $r->user()->allows('finance') ? $destination : null, 'has_receipt' => (bool) $w->receipt_path],
            'paymentAccounts' => $r->user()->allows('finance') ? app(PaymentAccounts::class)->listing($r->user()) : [],
            'events' => DB::table('withdrawal_events')->join('users', 'users.id', '=', 'actor_id')->where('withdrawal_id', $id)->orderBy('withdrawal_events.id')->get(['event', 'note', 'withdrawal_events.created_at', 'users.name'])]);
    }

    public function review(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|max:1000']);
        $s->review($r->user(), $id, $d['decision'], $d['reason']);
        $this->notifyPartnerWithdrawal($id, $d['decision']);

        return back()->with('success', __('portal.flash_withdrawal_review'));
    }

    public function settle(Request $r, string $id, ManualPayoutService $s): RedirectResponse
    {
        abort_unless($r->user()->allows('finance'), 403);
        $d = $r->validate(['payment_account_id' => 'nullable|integer|exists:payment_accounts,id', 'receipt' => 'required|file|mimes:pdf,jpg,jpeg,png|max:5120', 'payment_reference' => 'required|string|max:100', 'paid_at' => ['required', 'date', function ($attribute, $value, $fail) {
            if (Carbon::parse($value, 'Asia/Dhaka')->isFuture()) {
                $fail(__('portal.err_wd_paid_at_future'));
            }
        }], 'note' => 'required|string|max:1000']);
        $s->settle($r->user(), $id, $r->file('receipt'), strtoupper(trim($d['payment_reference'])), Carbon::parse($d['paid_at'], 'Asia/Dhaka')->utc()->toDateTimeString(), $d['note'], isset($d['payment_account_id']) ? (int) $d['payment_account_id'] : null);
        $this->notifyPartnerWithdrawal($id, 'paid');

        return back()->with('success', __('portal.flash_withdrawal_paid'));
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
            __($template, [
                'amount' => $withdrawal->amount,
                'channel' => $withdrawal->channel,
            ], $partner->preferredLocale()),
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
