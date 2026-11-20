<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\CourierOptions;
use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The courier page's Next button: the courier staff confirmed for an approved order.
 */
class ShipWithCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier_provider' => ['required', 'string', Rule::in(CourierOptions::knownProviders())],
            // Only a manual courier has no booking system, so only it needs a number typed in by hand.
            'tracking' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn (): bool => CourierOptions::isManual($this->input('courier_provider')))],
            'branch_id' => ['nullable', 'integer'],
            'note' => ReasonFields::rule('order.courier_change', 500),
            'idempotency_key' => ['required', 'uuid'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['tracking.required' => __('portal.err_dispatch_tracking')];
    }
}
