<?php

namespace App\Http\Controllers;

use App\Domain\Finance\CompanyFunds;
use App\Domain\Finance\FundSpending;
use App\Domain\Payouts\PayoutReceipts;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Owners and finance staff record money leaving a company fund and keep the list of expense heads.
 */
class FundSpendingController extends Controller
{
    public function store(Request $request, FundSpending $spending): RedirectResponse|JsonResponse
    {
        abort_unless(FundSpending::canManage($request->user()), 403);
        $data = $request->validate([
            'fund' => ['required', Rule::in(CompanyFunds::keys())],
            'expense_head_id' => 'required|integer|exists:fund_expense_heads,id',
            'amount' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,2})?$/D', 'not_regex:/^0+(\.0+)?$/D'],
            'spent_on' => ['required', 'date_format:Y-m-d', function (string $attribute, mixed $value, \Closure $fail): void {
                if (CarbonImmutable::parse($value, 'Asia/Dhaka')->isFuture()) {
                    $fail(__('funds.err_future_date'));
                }
            }],
            'payee' => 'required|string|max:150',
            'payment_method' => ['required', Rule::in(PayoutReceipts::Methods)],
            'reference' => 'nullable|required_unless:payment_method,cash|string|max:100',
            'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120',
            'note' => 'nullable|string|max:1000',
            'request_key' => 'required|uuid',
        ]);
        $id = $spending->request($request->user(), $data, $request->file('receipt'));

        return $this->done($request, ['id' => $id], __('funds.flash_spend_recorded'));
    }

    public function review(Request $request, int $id, FundSpending $spending): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['decision' => 'required|in:approved,rejected', 'reason' => 'required|string|min:5|max:1000',
            'self_approve' => 'sometimes|boolean', 'receipt' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:5120']);
        $spending->review($request->user(), $id, $data['decision'], $data['reason'], $request->boolean('self_approve'), $request->file('receipt'));

        return $this->done($request, [], __($data['decision'] === 'approved' ? 'funds.flash_spend_approved' : 'funds.flash_spend_rejected'));
    }

    public function cancel(Request $request, int $id, FundSpending $spending): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);
        $spending->cancel($request->user(), $id, $data['reason']);

        return $this->done($request, [], __('self_approval.flash_spend_cancelled'));
    }

    public function storeHead(Request $request, FundSpending $spending): RedirectResponse|JsonResponse
    {
        abort_unless(FundSpending::canManage($request->user()), 403);
        $id = $spending->createHead($request->user(), $request->validate($this->headRules()));

        return $this->done($request, ['id' => $id], __('funds.flash_head_saved'));
    }

    public function updateHead(Request $request, int $id, FundSpending $spending): RedirectResponse|JsonResponse
    {
        abort_unless(FundSpending::canManage($request->user()), 403);
        $data = $request->validate([...$this->headRules($id), 'is_active' => 'required|boolean']);
        $spending->updateHead($request->user(), $id, $data);

        return $this->done($request, [], __('funds.flash_head_saved'));
    }

    /** @return array<string, mixed> */
    private function headRules(?int $ignoreId = null): array
    {
        return [
            'name_en' => ['required', 'string', 'max:120', Rule::unique('fund_expense_heads', 'name_en')->ignore($ignoreId)],
            'name_bn' => 'required|string|max:120',
            'for_tours' => 'sometimes|boolean',
        ];
    }

    /** @param array<string, mixed> $payload */
    private function done(Request $request, array $payload, string $message): RedirectResponse|JsonResponse
    {
        return $request->expectsJson()
            ? response()->json(['data' => $payload, 'message' => $message])
            : back()->with('success', $message);
    }
}
