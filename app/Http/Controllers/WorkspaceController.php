<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Dealer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

abstract class WorkspaceController extends Controller
{
    protected function admin(Request $r): void
    {
        if (! Gate::allows('admin')) {
            AuditEvent::record('access.denied', ['route' => $r->path()]);
            abort(403);
        }
    }

    protected function stepUp(Request $r): void
    {
        if (app()->environment('local') && config('dealer.demo_enabled')) {
            return;
        }
        $r->validate(['password' => 'required|string']);
        if (! Hash::check($r->password, $r->user()->password)) {
            throw ValidationException::withMessages(['password' => 'Confirm your current password.']);
        }
    }

    protected function dealerAccess(Request $r, Dealer $d): void
    {
        if (! Gate::allows('view', $d)) {
            AuditEvent::record('access.denied', ['resource' => 'dealer', 'subject_id' => $d->id]);
            abort(404);
        }
    }

    protected function dealerRows(Builder $query): Collection
    {
        return $query->with('user:id,name,email')->orderByDesc('id')->get()->map(fn ($d) => [...$d->only(['id', 'code', 'level', 'division', 'district', 'upazila', 'union_name', 'status', 'business_name', 'name_bn', 'mobile', 'created_at']), 'name' => $d->user->name, 'email' => $d->user->email]);
    }
}
