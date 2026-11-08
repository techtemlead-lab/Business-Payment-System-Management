<?php

namespace App\Http\Controllers;

use App\Domain\Orders\DatedSchemes;
use App\Models\DatedScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DatedSchemeController extends Controller
{
    public function store(Request $request, DatedSchemes $schemes): RedirectResponse
    {
        abort_unless(DatedSchemes::canManage($request->user()), 403);
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'starts_on' => 'required|date_format:Y-m-d',
            'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'product_ids' => 'required|array|min:1|max:200',
            'product_ids.*' => ['integer', Rule::exists('products', 'id')->where('active', true)],
            'min_quantity' => 'required|integer|min:1|max:100000',
            'benefit_type' => ['required', Rule::in(DatedScheme::BenefitTypes)],
            'benefit_text' => 'required|string|max:500',
            'free_product_id' => ['nullable', 'integer', 'required_with:free_quantity', 'prohibited_unless:benefit_type,free_goods', Rule::exists('products', 'id')->where('active', true)],
            'free_quantity' => 'nullable|integer|min:1|max:100000|required_with:free_product_id|prohibited_unless:benefit_type,free_goods',
        ]);
        $schemes->add($request->user(), $data);

        return back()->with('success', __('dealer_tools.scheme_saved'));
    }

    public function destroy(Request $request, DatedScheme $datedScheme, DatedSchemes $schemes): RedirectResponse
    {
        $schemes->remove($request->user(), $datedScheme);

        return back()->with('success', __('dealer_tools.scheme_removed'));
    }
}
