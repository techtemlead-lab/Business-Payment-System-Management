<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class DecideOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('order_review') ?? false;
    }

    public function rules(): array
    {
        return ['status' => 'required|in:approved,held,rejected', 'reason' => 'required|string|max:1000', 'allow_credit' => 'sometimes|boolean'];
    }
}
