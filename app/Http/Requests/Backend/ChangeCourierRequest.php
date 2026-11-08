<?php

namespace App\Http\Requests\Backend;

use App\Domain\Orders\CourierOptions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeCourierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return ($this->user()?->allows('inventory') || $this->user()?->allows('order_review')) ?? false;
    }

    /**
     * The new courier must be one the owner switched on; the note for the dealer is optional.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'courier' => ['required', 'string', Rule::in(CourierOptions::knownProviders())],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
