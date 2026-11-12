<?php

namespace App\Http\Requests\Backend;

use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

class ConfirmDeliveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    public function rules(): array
    {
        return ['reason' => ReasonFields::rule('order.delivery_confirm', 1000)];
    }
}
