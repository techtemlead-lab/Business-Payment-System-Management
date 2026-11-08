<?php

namespace App\Http\Controllers;

use App\Models\ProductConcern;
use App\Support\Audit;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The owner's list of health concern tags. Named from the product editor.
 */
class ProductConcernController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);

        $data = $this->validatedNames($request);
        $concern = ProductConcern::query()->create($data);
        Audit::record($request->user()->id, 'product_concern.created', (string) $concern->id, $data);

        return back()->with('success', __('product_facts.flash_concern_added'));
    }

    public function update(Request $request, ProductConcern $productConcern): RedirectResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);

        $data = $this->validatedNames($request, $productConcern);
        $productConcern->update($data);
        Audit::record($request->user()->id, 'product_concern.updated', (string) $productConcern->id, $data);

        return back()->with('success', __('product_facts.flash_concern_updated'));
    }

    public function destroy(Request $request, ProductConcern $productConcern): RedirectResponse
    {
        abort_unless($request->user()->allows('catalog'), 403);

        $details = $productConcern->only(['name_bn', 'name_en']);
        $productConcern->delete();
        Audit::record($request->user()->id, 'product_concern.deleted', (string) $productConcern->id, $details);

        return back()->with('success', __('product_facts.flash_concern_deleted'));
    }

    /**
     * @return array{name_bn: string, name_en: string}
     */
    private function validatedNames(Request $request, ?ProductConcern $concern = null): array
    {
        return $request->validate([
            'name_bn' => ['required', 'string', 'max:80', ProductConcern::NoCureWordingRule, Rule::unique('product_concerns', 'name_bn')->ignore($concern?->id)],
            'name_en' => ['required', 'string', 'max:80', ProductConcern::NoCureWordingRule, Rule::unique('product_concerns', 'name_en')->ignore($concern?->id)],
        ], [
            '*.not_regex' => __('product_facts.err_concern_cure_wording'),
        ]);
    }
}
