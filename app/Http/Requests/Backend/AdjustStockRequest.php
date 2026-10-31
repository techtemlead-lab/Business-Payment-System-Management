<?php

namespace App\Http\Requests\Backend;

use App\Domain\Inventory\BranchOperations;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class AdjustStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->allows('inventory') ?? false;
    }

    public function rules(): array
    {
        $kind = (string) $this->input('kind', '');
        $usesDirectionalKind = in_array($kind, [BranchOperations::KindProductIn, BranchOperations::KindProductOut], true);

        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'kind' => ['nullable', 'string', Rule::in([BranchOperations::KindProductIn, BranchOperations::KindProductOut])],
            'quantity' => $usesDirectionalKind
                ? ['required', 'integer', 'min:1', 'max:100000']
                : ['required', 'integer', 'not_in:0', 'min:-100000', 'max:100000'],
            'reason_code' => [
                'nullable',
                'string',
                'max:40',
                Rule::when(
                    $kind === BranchOperations::KindProductIn,
                    [Rule::in(BranchOperations::ProductInReasonCodes)],
                ),
                Rule::when(
                    $kind === BranchOperations::KindProductOut,
                    [Rule::in(BranchOperations::ProductOutReasonCodes)],
                ),
            ],
            'reason' => ['required', 'string', 'max:1000'],
            'reference' => ['required', 'uuid'],
            'branch_id' => ['nullable', 'integer', 'exists:company_branches,id'],
            'expires_on' => [
                Rule::excludeIf($kind !== BranchOperations::KindProductIn),
                'nullable',
                'date',
                'after_or_equal:today',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $kind = (string) $this->input('kind', '');
            if (! in_array($kind, [BranchOperations::KindProductIn, BranchOperations::KindProductOut], true)) {
                return;
            }

            $reasonCode = trim((string) $this->input('reason_code', ''));
            $reason = trim((string) $this->input('reason', ''));
            $candidate = $reasonCode !== '' ? $reasonCode : $reason;
            $allowed = $kind === BranchOperations::KindProductIn
                ? BranchOperations::ProductInReasonCodes
                : BranchOperations::ProductOutReasonCodes;

            if ($candidate === '') {
                $validator->errors()->add('reason', __('portal.err_stock_reason_required'));

                return;
            }

            if (
                ! str_contains($candidate, ' ')
                && preg_match('/^[a-z][a-z0-9_]*$/', $candidate) === 1
                && ! in_array($candidate, $allowed, true)
            ) {
                $validator->errors()->add('reason_code', __('portal.err_stock_reason_code', ['kind' => $kind]));
            }
        });
    }
}
