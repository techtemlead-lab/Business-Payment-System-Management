<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LocaleController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', Rule::in(config('madina.locales', ['en', 'bn']))],
        ]);
        $request->session()->put('locale', $data['locale']);
        cookie()->queue(cookie('locale', $data['locale'], 60 * 24 * 365));
        if ($request->user()) {
            $request->user()->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}
