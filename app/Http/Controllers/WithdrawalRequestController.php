<?php

namespace App\Http\Controllers;

use App\Contracts\PayoutProvider;
use App\Models\WithdrawalRequest;
use App\Services\FinancialCalculator;
use App\Services\WithdrawalService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class WithdrawalRequestController extends WorkspaceController
{
    public function release(Request $request, WithdrawalRequest $withdrawalRequest, PayoutProvider $provider): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        if ($withdrawalRequest->status !== 'approved') {
            throw ValidationException::withMessages(['withdrawal' => 'Company approval is required before payment release.']);
        }
        if (! $provider->available()) {
            throw ValidationException::withMessages(['provider' => 'Payment release requires a configured and verified payout provider. This request remains approved with funds reserved.']);
        }
        throw ValidationException::withMessages(['provider' => 'The provider payment and reconciliation workflow must be verified before releasing funds.']);
    }

    public function store(Request $request, FinancialCalculator $calculator, WithdrawalService $service): RedirectResponse
    {
        abort_unless($request->user()->role === 'dealer', 403);
        $validated = $request->validate(['amount' => 'required|string|max:20', 'idempotency_key' => 'required|uuid', 'note' => 'nullable|string|max:500']);
        try {
            $amount = $calculator->parseMoney($validated['amount']);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['amount' => 'Enter a positive BDT amount with up to two decimal places.']);
        }
        $service->request($request->user()->dealer->id, $amount, $validated['idempotency_key'], $validated['note'] ?? null);

        return back()->with('success', 'Withdrawal request sent to the company. Funds are reserved for review; no payment has been made.');
    }

    public function update(Request $request, WithdrawalRequest $withdrawalRequest, WithdrawalService $service): RedirectResponse
    {
        $isCompany = $request->user()->role === 'admin';
        if ($isCompany) {
            $this->admin($request);
        } else {
            abort_unless($request->user()->dealer?->id === $withdrawalRequest->dealer_id, 404);
        }
        $this->stepUp($request);
        $validated = $request->validate(['status' => $isCompany ? 'required|in:approved,declined' : 'required|in:cancelled', 'reason' => 'required|string|min:5|max:500']);
        $service->review($withdrawalRequest->id, $validated['status'], $validated['reason'], $request->user()->id, $isCompany);

        return back()->with('success', $validated['status'] === 'approved' ? 'Withdrawal approved for payment processing. Funds remain reserved; payment requires verified provider evidence.' : 'Request closed. Reserved funds returned to the available balance.');
    }
}
