<?php

namespace App\Http\Requests\Backend;

use App\Domain\Finance\DistributionSchedule;
use Illuminate\Foundation\Http\FormRequest;

class PreviewDistributionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('finance') ?? false;
    }

    public function rules(): array
    {
        return [
            'account_type' => 'required|in:dealer,sub_dealer',
            'mrp' => ['required', 'string', 'regex:/^\d{1,11}(\.\d{1,2})?$/D'],
            'discount_percent' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D'],
            'company_share_percent' => ['sometimes', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D'],
            'components' => ['sometimes', 'array:'.implode(',', array_keys(DistributionSchedule::defaults('sub_dealer')))],
            'components.*' => ['required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/D'],
            'rounding' => 'sometimes|in:reject,half_up',
        ];
    }
}
