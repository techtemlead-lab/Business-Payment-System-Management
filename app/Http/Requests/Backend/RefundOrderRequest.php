<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\OrderRefund;
use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && OrderRefund::canRefund($user);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'], 'reason' => ReasonFields::rule('order.refund', 1000, 3)];
    }
}
