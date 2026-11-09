<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\PaymentProof;
use App\Models\Order;
use App\Support\TransactionReference;
use Illuminate\Foundation\Http\FormRequest;

class ClaimPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        abort_unless($order instanceof Order && $order->user_id === $this->user()?->id, 404);

        return $this->user()?->status === 'active' && in_array($this->user()?->role, ['dealer', 'sub_dealer'], true);
    }

    protected function prepareForValidation(): void
    {
        TransactionReference::tidy($this, 'reference');
    }

    public function rules(): array
    {
        // By Office: the partner enters nothing, the server sets the reference and the amount.
        $typedDetail = ['required_unless:channel,Office', 'nullable', 'prohibited_if:channel,Office'];

        return [
            'payment_account_id' => 'nullable|prohibited_if:channel,Office|integer|exists:payment_accounts,id',
            'channel' => 'required|in:bKash,Nagad,Rocket,Bank,Office',
            'reference' => [...$typedDetail, 'string', TransactionReference::MinRule, 'max:100'],
            'amount' => [...$typedDetail, 'regex:/^\d{1,9}(\.\d{1,2})?$/D'],
            // No payment gateway yet, so finance needs a screenshot of the transfer to check it.
            'proof' => [...$typedDetail, ...explode('|', PaymentProof::Rules)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...TransactionReference::messages('reference'),
            ...array_fill_keys(['reference.prohibited_if', 'amount.prohibited_if', 'proof.prohibited_if', 'payment_account_id.prohibited_if'], __('order_payment.val_nothing_needed')),
        ];
    }
}
