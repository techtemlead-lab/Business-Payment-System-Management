<?php

namespace App\Http\Requests\Backend;

use App\Domain\Finance\FinanceSettings;
use App\Domain\Orders\CourierOptions;
use App\Domain\Orders\PaymentProof;
use App\Support\TransactionReference;
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

    private const ServerSetsPayment = 'prohibited_if:channel,Office,Account';

    public function authorize(): bool
    {
        return $this->user()?->status === 'active' && in_array($this->user()?->role, ['dealer', 'sub_dealer'], true);
    }

    protected function prepareForValidation(): void
    {
        TransactionReference::tidy($this, 'reference');
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
            'reference' => [...$this->paymentDetailRules($paidWithOrder), 'string', TransactionReference::MinRule, 'max:100'],
            'payment_account_id' => ['nullable', self::ServerSetsPayment, 'integer', 'exists:payment_accounts,id'],
            'channel' => ($paidWithOrder ? 'required' : 'nullable|required_with:reference').'|in:bKash,Nagad,Rocket,Bank,Office,Account',
            'amount' => [...$this->paymentDetailRules($paidWithOrder), 'required_with:reference', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'],
            'proof' => [...$this->paymentDetailRules($paidWithOrder), 'required_with:reference', ...explode('|', PaymentProof::Rules)],
            ...array_fill_keys(self::SellerFields, 'prohibited'),
        ];
    }

    /**
     * A detail the partner types for a bank or mobile payment. By Office and From My Account
     * take none of them: the server sets the reference and the amount.
     *
     * @return list<string>
     */
    private function paymentDetailRules(bool $paidWithOrder): array
    {
        return [...($paidWithOrder ? ['required_unless:channel,Office,Account'] : []), 'nullable', self::ServerSetsPayment];
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
            'reference.required_unless' => __('portal.order_val_pay_reference_needed'),
            ...TransactionReference::messages('reference'),
            'channel.required' => __('portal.order_val_pay_channel_needed'),
            'amount.required' => __('portal.order_val_pay_amount_needed'),
            'amount.required_unless' => __('portal.order_val_pay_amount_needed'),
            'proof.required' => __('portal.order_val_pay_proof_needed'),
            'proof.required_unless' => __('portal.order_val_pay_proof_needed'),
            ...array_fill_keys(['reference.prohibited_if', 'amount.prohibited_if', 'proof.prohibited_if', 'payment_account_id.prohibited_if'], __('order_payment.val_nothing_needed')),
        ];
    }
}
