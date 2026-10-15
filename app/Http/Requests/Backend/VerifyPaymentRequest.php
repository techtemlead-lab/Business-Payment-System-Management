<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('finance') ?? false;
    }

    public function rules(): array
    {
        return ['approve' => 'required|boolean', 'reason' => 'required|string|max:1000'];
    }
}
