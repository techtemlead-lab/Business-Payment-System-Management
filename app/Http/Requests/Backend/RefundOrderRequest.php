<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\OrderRefund;
use Illuminate\Foundation\Http\FormRequest;

class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && OrderRefund::canRefund($user);
    }

    /** @return array<string, string> */
    public function rules(): array
    {
        return ['amount' => ['required', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'], 'reason' => 'required|string|min:3|max:1000'];
    }
}
