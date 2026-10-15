<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class DispatchOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    public function rules(): array
    {
        return [
            'courier' => 'required|string|max:100', 'tracking' => 'required|string|max:100',
            'eta' => 'nullable|date', 'idempotency_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:100', 'items.*' => 'array:line_id,quantity',
            'items.*.line_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
        ];
    }
}
