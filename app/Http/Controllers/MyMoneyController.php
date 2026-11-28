<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerKycDocuments;
use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Accounts\PartnerPendingMoney;
use App\Domain\Accounts\PartnerSalaryNotes;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\FundSpendPartners;
use App\Domain\Finance\WithdrawalSecurityWait;
use App\Domain\Payouts\ManualPayoutService;
use App\Domain\Payouts\PartnerPayoutAccounts;
use App\Domain\Payouts\PayoutLimits;
use App\Domain\Payouts\PayoutReceipts;
use App\Domain\Payouts\WithdrawalMethods;
use App\Domain\Payouts\WithdrawalWeek;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A dealer's or sub-dealer's money on one page: what they have, where it came from, and every
 * movement.
 */
class MyMoneyController extends Controller
{
    public function show(Request $request, PartnerMoneyBook $book, FinanceSettings $settings, PayoutReceipts $receipts): Response
    {
        $partner = $request->user();
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 403);
        $kycBlock = $settings->needsKycBeforeWithdrawal($partner);
        $nidBlock = app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner);
        $wait = app(WithdrawalSecurityWait::class);
        $opensAt = $wait->opensAt($partner);
        $pending = app(PartnerPendingMoney::class)->for($partner);
        // Every saved account is sent: the dialog greys out one whose way the owner switched off, and the wallet card lists them all.
        $methods = app(WithdrawalMethods::class)->enabled();
        $savedAccounts = app(PartnerPayoutAccounts::class)->listFor($partner);

        return Inertia::render('MyMoney', [
            'section' => 'my-money',
            'summary' => $book->summary($partner),
            'cards' => $book->incomeCards($partner),
            'table' => $book->table($request, $partner),
            'withdrawal_requests' => $book->withdrawalRequests($partner),
            'withdrawal_history' => $book->withdrawalHistory($request, $partner),
            'not_earned_area' => PartnerPendingMoney::notReceived($book->notEarnedAreaCommission($partner), $pending['awaiting_close']),
            'held_commission' => $book->heldCommission($partner, 20, false),
            'pending' => $pending,
            'benefits' => app(FundSpendPartners::class)->linesFor($partner),
            'salary_notes' => app(PartnerSalaryNotes::class)->for($partner),
            'withdraw' => ['can' => ! $kycBlock && ! $nidBlock, 'kyc_block' => $kycBlock, 'nid_block' => $nidBlock, 'nid_request' => app(PartnerKycDocuments::class)->nidRequestState($partner), 'saved_accounts' => $savedAccounts, 'methods' => $methods, 'week' => app(WithdrawalWeek::class)->partnerInfo(), 'security_wait' => $opensAt === null ? null : $wait->reason($opensAt), 'minimum' => (string) $settings->withdrawalMinimum()['amount'],
                'channel_limits' => app(PayoutLimits::class)->settings()['per_channel'],
                'tin_last4' => trim((string) $partner->tin) === '' ? null : substr((string) $partner->tin, -4)],
            'reserve_receipts' => $receipts->reservePayoutsFor($partner),
            'certificates' => [
                'years' => range((int) now('Asia/Dhaka')->format('Y'), max(2024, (int) ($partner->created_at?->timezone('Asia/Dhaka')->format('Y') ?? 2024))),
                'rank' => $partner->role === 'dealer' && DB::table('dealer_ranks')->where('user_id', $partner->id)->whereNotIn('current_rank', ['', 'dealer'])->exists(),
            ],
        ]);
    }
}
