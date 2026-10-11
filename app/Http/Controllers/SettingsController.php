<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends WorkspaceController
{
    public function settings(Request $r): Response
    {
        $this->admin($r);

        return Inertia::render('Settings');
    }

    public function saveSettings(Request $r): RedirectResponse
    {
        $this->admin($r);
        $v = $r->validate(['company_name' => 'required|string|max:100', 'contact_email' => 'nullable|email|max:150', 'contact_phone' => 'nullable|string|max:25']);
        DB::transaction(function () use ($v) {
            BusinessConfig::findOrFail(1)->update($v);
            AuditEvent::record('company.updated', ['fields' => array_keys($v)]);
        });

        return back()->with('success', 'Company settings saved.');
    }
}
