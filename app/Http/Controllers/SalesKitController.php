<?php

namespace App\Http\Controllers;

use App\Domain\Content\SalesKit;
use App\Models\SalesKitItem;
use App\Support\ReasonFields;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesKitController extends Controller
{
    public function store(Request $request, SalesKit $kit): RedirectResponse
    {
        abort_unless(SalesKit::canManage($request->user()), 403);
        $data = $request->validate($this->rules('required'));
        $kit->add($request->user(), $data, $request->file('file'));

        return back()->with('success', __('dealer_tools.kit_saved'));
    }

    public function update(Request $request, SalesKitItem $salesKitItem, SalesKit $kit): RedirectResponse
    {
        abort_unless(SalesKit::canManage($request->user()), 403);
        $data = $request->validate([...$this->rules('nullable'), 'reason' => ReasonFields::rule('partner.sales_kit_edit', 1000, 5)]);
        $kit->update($request->user(), $salesKitItem, [...$data, 'reason' => ReasonFields::text($data['reason'] ?? null)], $request->file('file'));

        return back()->with('success', __('dealer_tools.kit_updated'));
    }

    public function destroy(Request $request, SalesKitItem $salesKitItem, SalesKit $kit): RedirectResponse
    {
        $kit->remove($request->user(), $salesKitItem);

        return back()->with('success', __('dealer_tools.kit_removed'));
    }

    public function file(Request $request, SalesKitItem $salesKitItem, SalesKit $kit): StreamedResponse
    {
        return $kit->response($request->user(), $salesKitItem, $request->boolean('inline'));
    }

    /**
     * The same rules for adding and editing. An edit only needs a file when it replaces the old one.
     *
     * @return array<string, mixed>
     */
    private function rules(string $fileRule): array
    {
        return [
            'title' => 'required|string|max:150',
            'kind' => ['required', Rule::in(SalesKitItem::Kinds)],
            'language' => ['required', Rule::in(SalesKitItem::Languages)],
            'caption' => 'nullable|string|max:2000',
            'file' => [$fileRule, 'file', 'max:'.SalesKit::MaxKilobytes, 'mimes:'.implode(',', SalesKit::Extensions), 'mimetypes:'.implode(',', SalesKit::Mimes)],
        ];
    }
}
