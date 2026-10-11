<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\User;
use App\Services\LedgerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class DealerController extends WorkspaceController
{
    public function dealers(Request $r): Response
    {
        $this->admin($r);
        $r->validate(['q' => 'nullable|string|max:80', 'status' => 'nullable|in:active,pending,suspended,deactivated', 'level' => 'nullable|in:Divisional,District,Upazila / Thana,Union']);
        $q = Dealer::query()->with('user:id,name,email');
        if ($r->filled('q')) {
            $q->where(fn ($q) => $q->whereLike('code', '%'.$r->q.'%')->orWhereLike('business_name', '%'.$r->q.'%')->orWhereHas('user', fn ($u) => $u->whereLike('name', '%'.$r->q.'%')));
        }
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }
        if ($r->filled('level')) {
            $q->where('level', $r->level);
        }
        $page = $q->latest('id')->paginate(12)->withQueryString();
        $page->getCollection()->transform(fn ($d) => [...$d->only(['id', 'code', 'level', 'division', 'district', 'status', 'business_name']), 'name' => $d->user->name, 'email' => $d->user->email]);

        return Inertia::render('Dealers', ['dealers' => $page, 'filters' => $r->only('q', 'status', 'level')]);
    }

    public function createDealer(Request $r): RedirectResponse
    {
        $this->admin($r);
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
        $v = $r->validate(['name' => 'required|string|max:120', 'name_bn' => 'nullable|string|max:120', 'email' => 'required|email|max:160|unique:users,email', 'code' => 'required|regex:/^[A-Z0-9-]{3,30}$/|unique:dealers,code', 'level' => ['required', Rule::in(['Divisional', 'District', 'Upazila / Thana', 'Union'])], 'division' => ['required', Rule::in(['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'])], 'district' => 'nullable|string|max:100', 'upazila' => 'nullable|string|max:100', 'union_name' => 'nullable|string|max:100', 'mobile' => 'nullable|regex:/^\+?8801[3-9][0-9]{8}$/', 'business_name' => 'nullable|string|max:160', 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        if ($v['level'] !== 'Divisional' && empty($v['district'])) {
            throw ValidationException::withMessages(['district' => 'A district is required for this level.']);
        }
        if (in_array($v['level'], ['Upazila / Thana', 'Union']) && empty($v['upazila'])) {
            throw ValidationException::withMessages(['upazila' => 'An upazila or thana is required for this level.']);
        }
        if ($v['level'] === 'Union' && empty($v['union_name'])) {
            throw ValidationException::withMessages(['union_name' => 'A union is required for this level.']);
        }
        DB::transaction(function () use ($v) {
            $u = User::create(['name' => $v['name'], 'email' => strtolower($v['email']), 'password' => $v['password']]);
            $d = new Dealer(collect($v)->except(['name', 'email', 'password'])->all());
            $d->user_id = $u->id;
            $d->status = 'pending';
            $d->save();
            AuditEvent::record('dealer.created', ['status' => 'pending'], $d->id);
        });

        return redirect('/dealers')->with('success', 'Dealer created. Review the profile before activation.');
    }

    public function showDealer(Request $r, Dealer $dealer, LedgerService $ledger): Response
    {
        $this->dealerAccess($r, $dealer);

        return Inertia::render('DealerProfile', ['dealer' => $this->dealerRows(Dealer::whereKey($dealer->id))->first(), 'balances' => $ledger->balances($dealer->id)]);
    }

    public function updateDealer(Request $r, Dealer $dealer): RedirectResponse
    {
        $this->dealerAccess($r, $dealer);
        $isCompany = $r->user()->role === 'admin';
        $rules = ['name_bn' => 'nullable|string|max:120', 'business_name' => 'nullable|string|max:160'];
        if ($isCompany) {
            $this->stepUp($r);
            $r->merge(['email' => mb_strtolower(trim((string) $r->input('email')))]);
            $rules += ['name' => 'required|string|max:120', 'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($dealer->user_id)], 'mobile' => 'nullable|regex:/^\+?8801[3-9][0-9]{8}$/', 'level' => ['required', Rule::in(['Divisional', 'District', 'Upazila / Thana', 'Union'])], 'division' => ['required', Rule::in(['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'])], 'district' => [Rule::requiredIf($r->input('level') !== 'Divisional'), 'nullable', 'string', 'max:100'], 'upazila' => [Rule::requiredIf(in_array($r->input('level'), ['Upazila / Thana', 'Union'])), 'nullable', 'string', 'max:100'], 'union_name' => 'required_if:level,Union|nullable|string|max:100'];
        }
        $validated = $r->validate($rules);
        DB::transaction(function () use ($dealer, $validated, $isCompany): void {
            $record = Dealer::whereKey($dealer->id)->lockForUpdate()->firstOrFail();
            if ($isCompany) {
                $user = User::whereKey($record->user_id)->lockForUpdate()->firstOrFail();
                if ($user->email !== $validated['email']) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $user->update(collect($validated)->only(['name', 'email'])->all());
            }
            $record->update(collect($validated)->except(['name', 'email'])->all());
            AuditEvent::record('dealer.profile_updated', ['fields' => array_keys($validated)], $record->id);
        });

        return back()->with('success', 'Profile updated.');
    }

    public function statusDealer(Request $r, Dealer $dealer): RedirectResponse
    {
        $this->admin($r);
        $this->stepUp($r);
        $r->validate(['status' => 'required|in:active,suspended,deactivated', 'reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($r, $dealer) {
            $d = Dealer::whereKey($dealer->id)->lockForUpdate()->firstOrFail();
            $before = $d->status;
            $d->update(['status' => $r->status]);
            if ($r->status !== 'active') {
                DB::table('sessions')->where('user_id', $d->user_id)->delete();
            }
            AuditEvent::record('dealer.status_changed', ['before' => $before, 'after' => $r->status, 'reason' => $r->reason], $d->id);
        });

        return back()->with('success', 'Dealer status updated.');
    }
}
