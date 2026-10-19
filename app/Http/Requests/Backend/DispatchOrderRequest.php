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
            'courier' => 'required|string|max:100',
            'tracking' => 'nullable|string|max:100',
            'courier_provider' => 'nullable|string|in:pathao,steadfast,redx,paperfly,ecourier',
            'eta' => 'nullable|date', 'idempotency_key' => 'required|uuid',
            'items' => 'required|array|min:1|max:100', 'items.*' => 'array:line_id,quantity',
            'items.*.line_id' => 'required|integer|distinct',
            'items.*.quantity' => 'required|integer|min:1|max:10000',
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $provider = $this->input('courier_provider');
            $tracking = trim((string) $this->input('tracking', ''));
            if ($provider === null && $tracking === '') {
                $validator->errors()->add('tracking', 'Enter a tracking reference, or select an enabled courier API provider.');
            }
        });
    }
}
