<?php

namespace App\Http\Requests\Backend;

use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

class VerifyPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('finance') ?? false;
    }

    public function rules(): array
    {
        return ['approve' => 'required|boolean', 'reason' => ReasonFields::rule('payment.verify', 1000), 'cash_counted' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/D']];
    }
}
