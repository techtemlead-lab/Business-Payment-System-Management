<?php

namespace App\Http\Requests\Backend;

use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "The money came, approve the order" in one press: the payment check's fields (the reason and, for
 * cash at the office, the counted amount) plus the approval's upline replacements.
 */
class VerifyAndApproveOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && $user->allows('finance') && $user->allows('order_review');
    }

    /** @return array<int, int> */
    public function replacements(): array
    {
        $result = [];
        foreach ([1, 2, 3] as $generation) {
            if ($this->filled('referral_replacement_'.$generation)) {
                $result[$generation] = $this->integer('referral_replacement_'.$generation);
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'claim_id' => ['required', 'integer'],
            'reason' => ReasonFields::rule('payment.verify', 1000),
            'cash_counted' => ['nullable', 'regex:/^\d{1,12}(\.\d{1,2})?$/D'],
            'referral_replacement_1' => 'nullable|integer|exists:users,id',
            'referral_replacement_2' => 'nullable|integer|exists:users,id',
            'referral_replacement_3' => 'nullable|integer|exists:users,id',
        ];
    }
}
