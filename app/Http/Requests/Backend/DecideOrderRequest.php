<?php

namespace App\Http\Requests\Backend;

use Illuminate\Foundation\Http\FormRequest;

class DecideOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('order_review') ?? false;
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

    public function rules(): array
    {
        return ['status' => 'required|in:approved,held,rejected', 'reason' => 'required|string|max:1000', 'allow_credit' => 'sometimes|boolean', 'referral_replacement_1' => 'nullable|integer|exists:users,id', 'referral_replacement_2' => 'nullable|integer|exists:users,id', 'referral_replacement_3' => 'nullable|integer|exists:users,id'];
    }
}
