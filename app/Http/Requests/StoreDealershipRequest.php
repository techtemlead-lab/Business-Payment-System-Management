<?php

namespace App\Http\Requests;

use App\Services\WebsiteService;
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
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email'))), 'referral_code' => strtoupper(trim((string) $this->input('referral_code')))]);
    }

    public function rules(): array
    {
        $application = app(WebsiteService::class)->published()['application'];
        abort_unless($application['open'], 403, 'Dealership applications are currently closed.');
        $rules = [
            'name' => 'required|string|max:120',
            'dealer_type' => 'sometimes|required|in:dealer,area_dealer',
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
            'language' => 'nullable|in:en,bn',
            'referral_code' => ['nullable', 'string', 'max:30', Rule::exists('dealers', 'code')],
        ];
        $keys = array_column($application['questions'], 'id');
        $rules['additional_answers'] = $keys ? 'nullable|array:'.implode(',', $keys) : 'nullable|array|max:0';
        foreach ($application['questions'] as $question) {
            $active = empty($question['when_field']) || $this->input($question['when_field']) === $question['when_value'];
            $rules['additional_answers.'.$question['id']] = [Rule::excludeIf(! $active), $question['required'] && $active ? 'required' : 'nullable', 'string', 'max:1500'];
            if ($question['type'] === 'select') {
                $rules['additional_answers.'.$question['id']][] = Rule::in($question['options']);
            }
        }

        return $rules;
    }
}
