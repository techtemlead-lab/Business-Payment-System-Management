<?php

namespace App\Http\Controllers;

use App\Domain\Content\SalesKit;
use App\Models\SalesKitItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SalesKitController extends Controller
{
    public function store(Request $request, SalesKit $kit): RedirectResponse
    {
        abort_unless(SalesKit::canManage($request->user()), 403);
        $data = $request->validate([
            'title' => 'required|string|max:150',
            'kind' => ['required', Rule::in(SalesKitItem::Kinds)],
            'language' => ['required', Rule::in(SalesKitItem::Languages)],
            'caption' => 'nullable|string|max:2000',
            'file' => ['required', 'file', 'max:'.SalesKit::MaxKilobytes, 'mimes:'.implode(',', SalesKit::Extensions), 'mimetypes:'.implode(',', SalesKit::Mimes)],
        ]);
        $kit->add($request->user(), $data, $request->file('file'));

        return back()->with('success', __('dealer_tools.kit_saved'));
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
}
