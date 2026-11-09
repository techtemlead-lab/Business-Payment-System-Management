<?php

namespace App\Http\Controllers;

use App\Domain\Orders\DatedSchemes;
use App\Models\DatedScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class DatedSchemeController extends Controller
{
    public function store(Request $request, DatedSchemes $schemes): RedirectResponse
    {
        abort_unless(DatedSchemes::canManage($request->user()), 403);
        $data = $request->validate($this->rules());
        $schemes->add($request->user(), $data);

        return back()->with('success', __('dealer_tools.scheme_saved'));
    }

    public function update(Request $request, DatedScheme $datedScheme, DatedSchemes $schemes): RedirectResponse
    {
        abort_unless(DatedSchemes::canManage($request->user()), 403);
        $data = $request->validate([...$this->rules($datedScheme), 'reason' => FinanceOperationsController::Reason]);
        $schemes->update($request->user(), $datedScheme, $data);

        return back()->with('success', __('dealer_tools.scheme_updated'));
    }

    public function destroy(Request $request, DatedScheme $datedScheme, DatedSchemes $schemes): RedirectResponse
    {
        $schemes->remove($request->user(), $datedScheme);

        return back()->with('success', __('dealer_tools.scheme_removed'));
    }

    /**
     * The same rules for adding and editing.
     *
     * @return array<string, mixed>
     */
    private function rules(?DatedScheme $scheme = null): array
    {
        $coveredNow = $scheme === null ? [] : array_map('intval', $scheme->product_ids);
        $freeNow = $scheme?->free_product_id === null ? [] : [(int) $scheme->free_product_id];

        return [
            'title' => 'required|string|max:150',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'product_ids' => 'required|array|min:1|max:200',
            'product_ids.*' => ['integer', $this->onSaleOrAlready($coveredNow)],
            'min_quantity' => 'required|integer|min:1|max:100000',
            'benefit_type' => ['required', Rule::in(DatedScheme::BenefitTypes)],
            'benefit_text' => 'required|string|max:500',
            'free_product_id' => ['nullable', 'integer', 'required_with:free_quantity', 'prohibited_unless:benefit_type,free_goods', $this->onSaleOrAlready($freeNow)],
            'free_quantity' => 'nullable|integer|min:1|max:100000|required_with:free_product_id|prohibited_unless:benefit_type,free_goods',
        ];
    }

    /**
     * A scheme can only gain products that are on sale, but an edit may keep one that was hidden
     * from the catalog later, or the scheme could never be saved again.
     *
     * @param  list<int>  $alreadyOnScheme
     */
    private function onSaleOrAlready(array $alreadyOnScheme): Exists
    {
        return Rule::exists('products', 'id')->where(function ($query) use ($alreadyOnScheme): void {
            $query->where('active', true)->orWhereIn('id', $alreadyOnScheme);
        });
    }
}
