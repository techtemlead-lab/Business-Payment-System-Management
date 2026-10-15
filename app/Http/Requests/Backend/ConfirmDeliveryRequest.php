<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => 'required|string|max:1000'];
    }
}
