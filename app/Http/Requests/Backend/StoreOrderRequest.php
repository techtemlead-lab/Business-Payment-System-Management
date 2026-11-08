<?php

namespace App\Http\Requests\Backend;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\PaymentProof;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrderRequest extends FormRequest
{
    /**
     * Fields that would let a partner name a seller. Dealers and sub-dealers buy
     * only from the company; the office picks the company branch at dispatch.
     *
     * @var list<string>
     */
    public const SellerFields = ['seller_id', 'seller_user_id', 'seller_code', 'dealer_id', 'supplier_id', 'sponsor_id', 'upline_id', 'branch_id', 'fulfillment_branch_id'];

    public function authorize(): bool
    {
        return $this->user()?->status === 'active' && in_array($this->user()?->role, ['dealer', 'sub_dealer'], true);
    }

    /**
     * While "Full payment required when ordering" is on, the payment (channel,
     * reference, amount) and its proof screenshot must come with the order.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $paidWithOrder = app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled'];

        return [
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:product_id,quantity',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'idempotency_key' => 'required|uuid',
            'note' => 'nullable|string|max:1000',
            // Only the couriers the owner switched on, or "company decides" (the default).
            'courier_choice' => ['nullable', 'string', Rule::in(app(CourierOptions::class)->choiceKeys())],
            'reference' => ($paidWithOrder ? 'required' : 'nullable').'|string|max:100',
            'payment_account_id' => 'nullable|integer|exists:payment_accounts,id',
            'channel' => ($paidWithOrder ? 'required' : 'nullable|required_with:reference').'|in:bKash,Nagad,Rocket,Bank',
            'amount' => [$paidWithOrder ? 'required' : 'nullable', 'required_with:reference', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'],
            'proof' => ($paidWithOrder ? 'required|' : 'nullable|required_with:reference|').PaymentProof::Rules,
            ...array_fill_keys(self::SellerFields, 'prohibited'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...array_combine(
                array_map(fn (string $field): string => $field.'.prohibited', self::SellerFields),
                array_fill(0, count(self::SellerFields), __('portal.order_val_company_only_seller')),
            ),
            'reference.required' => __('portal.order_val_pay_reference_needed'),
            'channel.required' => __('portal.order_val_pay_channel_needed'),
            'amount.required' => __('portal.order_val_pay_amount_needed'),
            'proof.required' => __('portal.order_val_pay_proof_needed'),
        ];
    }
}
