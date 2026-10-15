<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    public function rules(): array
    {
        return [
            'product_id' => 'required|integer|exists:products,id',
            'quantity' => 'required|integer|not_in:0|min:-100000|max:100000',
            'reason' => 'required|string|max:1000', 'reference' => 'required|uuid',
        ];
    }
}
