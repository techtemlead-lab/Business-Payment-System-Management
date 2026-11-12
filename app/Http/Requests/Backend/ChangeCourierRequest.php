<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\CourierOptions;
use App\Support\ReasonFields;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) ($this->user()?->allows('inventory') || $this->user()?->allows('order_review'));
    }

    /**
     * The new courier must be one the owner switched on; the note for the dealer follows the owner's choice.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier' => ['required', 'string', Rule::in(CourierOptions::knownProviders())],
            'note' => ReasonFields::rule('order.courier_change', 500),
        ];
    }
}
