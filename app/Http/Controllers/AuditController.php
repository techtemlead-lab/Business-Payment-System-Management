<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AuditController extends WorkspaceController
{
    public function audit(Request $r): Response
    {
        $this->admin($r);
        $r->validate(['q' => 'nullable|string|max:80']);
        $q = AuditEvent::query();
        if ($r->filled('q')) {
            $q->where('action', 'like', '%'.$r->q.'%');
        }

        return Inertia::render('Audit', ['events' => $q->latest('id')->paginate(20)->withQueryString(), 'filters' => $r->only('q')]);
    }
}
