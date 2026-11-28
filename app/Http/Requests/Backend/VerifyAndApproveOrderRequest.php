<?php

namespace App\Http\Requests\Backend;

use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;

/**
 * "The money came, approve the order" in one press: the payment check's fields (the reason and, for
 * cash at the office, the counted amount) plus the approval's upline replacements. For cash paid at the
 * office on an office-pickup order, `hand_over` also gives the goods out in the same press (owner,
 * 2026-11-28), with the hand-over's own fields.
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
            'hand_over' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required_if_accepted:hand_over', 'nullable', 'uuid'],
            'branch_id' => ['nullable', 'integer'],
            // The hand-over's note follows the owner's reason-box setting only when the goods go out now.
            'handover_note' => $this->boolean('hand_over') ? ReasonFields::rule('order.delivery_confirm', 500) : ['nullable', 'string', 'max:500'],
        ];
    }
}
