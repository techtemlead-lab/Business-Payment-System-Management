<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerMoneyBook;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Payouts\PayoutReceipts;
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

        return Inertia::render('MyMoney', [
            'section' => 'my-money',
            'summary' => $book->summary($partner),
            'cards' => $book->incomeCards($partner),
            'table' => $book->table($request, $partner),
            'withdrawal_requests' => $book->withdrawalRequests($partner),
            'withdraw' => ['can' => ! $kycBlock, 'kyc_block' => $kycBlock, 'minimum' => (string) $settings->withdrawalMinimum()['amount']],
            'reserve_receipts' => $receipts->reservePayoutsFor($partner),
            'certificates' => [
                'years' => range((int) now('Asia/Dhaka')->format('Y'), max(2024, (int) ($partner->created_at?->timezone('Asia/Dhaka')->format('Y') ?? 2024))),
                'rank' => $partner->role === 'dealer' && DB::table('dealer_ranks')->where('user_id', $partner->id)->whereNotIn('current_rank', ['', 'dealer'])->exists(),
            ],
        ]);
    }
}
