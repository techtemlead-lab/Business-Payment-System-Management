<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDealershipRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:120',
            'email' => 'required|email|max:160',
            'mobile' => ['required', 'regex:/^\+?8801[3-9][0-9]{8}$/'],
            'business_name' => 'required|string|max:160',
            'business_address' => 'required|string|max:500',
            'level' => ['required', Rule::in(['Divisional', 'District', 'Upazila / Thana', 'Union'])],
            'division' => ['required', Rule::in(['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'])],
            'district' => [Rule::requiredIf($this->input('level') !== 'Divisional'), 'nullable', 'string', 'max:100'],
            'upazila' => [Rule::requiredIf(in_array($this->input('level'), ['Upazila / Thana', 'Union'])), 'nullable', 'string', 'max:100'],
            'union_name' => 'required_if:level,Union|nullable|string|max:100',
            'message' => 'nullable|string|max:1500',
            'consent' => 'accepted',
        ];
    }
}
