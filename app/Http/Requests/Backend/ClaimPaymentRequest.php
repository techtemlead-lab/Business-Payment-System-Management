<?php

namespace App\Http\Requests\Backend;

use App\Models\Order;
use Illuminate\Foundation\Http\FormRequest;

class ClaimPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $order = $this->route('order');
        abort_unless($order instanceof Order && $order->user_id === $this->user()?->id, 404);

        return $this->user()?->status === 'active' && in_array($this->user()?->role, ['dealer', 'sub_dealer'], true);
    }

    public function rules(): array
    {
        return [
            'payment_account_id' => 'nullable|integer|exists:payment_accounts,id',
            'channel' => 'required|in:bKash,Nagad,Rocket,Bank', 'reference' => 'required|string|max:100',
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'],
        ];
    }
}
