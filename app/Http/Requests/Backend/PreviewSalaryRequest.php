<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class PreviewSalaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('finance') ?? false;
    }

    public function rules(): array
    {
        return [
            'monthly_purchases' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/D'],
            'rounding' => 'sometimes|in:reject,half_up',
        ];
    }
}
