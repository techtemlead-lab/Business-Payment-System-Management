<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->status === 'active' && in_array($this->user()?->role, ['dealer', 'sub_dealer'], true);
    }

    public function rules(): array
    {
        return [
            'items' => 'required|array|min:1|max:100',
            'items.*' => 'array:product_id,quantity',
            'items.*.product_id' => 'required|integer|distinct|exists:products,id',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
            'idempotency_key' => 'required|uuid',
            'note' => 'nullable|string|max:1000',
            'reference' => 'nullable|string|max:100',
            'channel' => 'nullable|required_with:reference|in:bKash,Nagad,Rocket,Bank',
            'amount' => ['nullable', 'required_with:reference', 'regex:/^\d{1,9}(\.\d{1,2})?$/D'],
        ];
    }
}
