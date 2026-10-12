<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\User;
use App\Services\BangladeshLocations;
use App\Services\LedgerService;
use App\Services\ReferralService;
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
    public function dealers(Request $r, BangladeshLocations $catalog): Response
    {
        $this->admin($r);
        $r->validate(['dealer_type' => 'nullable|in:dealer,area_dealer']);
        $locationFilters = $catalog->canonicalFilters($r->validate(['division' => 'nullable|string|max:100', 'district' => 'nullable|string|max:100', 'upazila' => 'nullable|string|max:100', 'union_name' => 'nullable|string|max:100']));
        $r->merge($locationFilters);
        $r->validate(['q' => 'nullable|string|max:80', 'status' => 'nullable|in:active,pending,suspended,deactivated', 'level' => 'nullable|in:Divisional,District,Upazila / Thana,Union']);
        $q = Dealer::query()->with('user:id,name,email', 'referrer:id,user_id,code', 'referrer.user:id,name', 'referralVerifier:id,name');
        if ($r->filled('dealer_type')) {
            $q->where('dealer_type', $r->dealer_type);
        }
        if ($r->filled('q')) {
            $q->where(fn ($q) => $q->whereLike('code', '%'.$r->q.'%')->orWhereLike('business_name', '%'.$r->q.'%')->orWhereHas('user', fn ($u) => $u->whereLike('name', '%'.$r->q.'%')));
        }
        if ($r->filled('status')) {
            $q->where('status', $r->status);
        }
        if ($r->filled('level')) {
            $q->where('level', $r->level);
        }
        $locationOptions = [];
        $locationLabels = [];
        $legacyLocations = [];
        $locations = Dealer::query();
        foreach (BangladeshLocations::LEVELS as $location) {
            $recordedValues = (clone $locations)->whereNotNull($location)->where($location, '!=', '')->distinct()->orderBy($location)->pluck($location)->all();
            $options = $catalog->options($location, $locationFilters, $recordedValues);
            $locationOptions[$location] = $options['values'];
            $locationLabels[$location] = $options['labels'];
            $legacyLocations[$location] = $options['legacy'];
            if ($r->filled($location)) {
                $matchingValues = $catalog->matchingValues($location, $r->input($location), $locationFilters, $recordedValues);
                $q->whereIn($location, $matchingValues);
                $locations->whereIn($location, $matchingValues);
            }
        }
        $page = $q->latest('id')->paginate(12)->withQueryString();
        $page->getCollection()->transform(fn ($d) => [...$d->only(['id', 'code', 'dealer_type', 'level', 'division', 'district', 'upazila', 'union_name', 'status', 'business_name']), 'name' => $d->user->name, 'email' => $d->user->email, 'referrer_code' => $d->referrer?->code, 'referrer_name' => $d->referrer?->user->name, 'referral_verified_at' => $d->referral_verified_at, 'referral_verified_by_name' => $d->referralVerifier?->name]);

        return Inertia::render('Dealers', ['dealers' => $page, 'filters' => $r->only('q', 'status', 'level', 'dealer_type', 'division', 'district', 'upazila', 'union_name'), 'locationOptions' => $locationOptions, 'locationLabels' => $locationLabels, 'legacyLocations' => $legacyLocations, 'locationCatalog' => collect($catalog->metadata())->only(['source_url', 'repository_url', 'police_source_url', 'retrieved_at', 'counts'])]);
    }

    public function createDealer(Request $r, ReferralService $referrals): RedirectResponse
    {
        $this->admin($r);
        $r->merge(['email' => mb_strtolower(trim((string) $r->input('email'))), 'referrer_code' => strtoupper(trim((string) $r->input('referrer_code')))]);
        $v = $r->validate(['name' => 'required|string|max:120', 'name_bn' => 'nullable|string|max:120', 'email' => 'required|email|max:160|unique:users,email', 'code' => 'required|regex:/^[A-Z0-9-]{3,30}$/|unique:dealers,code', 'level' => ['required', Rule::in(['Divisional', 'District', 'Upazila / Thana', 'Union'])], 'division' => ['required', Rule::in(['Dhaka', 'Chattogram', 'Rajshahi', 'Khulna', 'Barishal', 'Sylhet', 'Rangpur', 'Mymensingh'])], 'district' => 'nullable|string|max:100', 'upazila' => 'nullable|string|max:100', 'union_name' => 'nullable|string|max:100', 'mobile' => 'nullable|regex:/^\+?8801[3-9][0-9]{8}$/', 'business_name' => 'nullable|string|max:160', 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
        $v += $r->validate(['referral_choice' => 'sometimes|required|in:none,referred', 'referrer_code' => 'required_if:referral_choice,referred|prohibited_if:referral_choice,none|nullable|string|max:30', 'referral_verified' => [Rule::requiredIf($r->filled('referrer_code')), Rule::when($r->filled('referrer_code'), ['accepted']), 'nullable', 'boolean'], 'application_id' => 'nullable|integer|exists:dealership_requests,id']);
        $v += $r->validate(['dealer_type' => 'sometimes|required|in:dealer,area_dealer']);
        if ($v['level'] !== 'Divisional' && empty($v['district'])) {
            throw ValidationException::withMessages(['district' => 'A district is required for this level.']);
        }
        if (in_array($v['level'], ['Upazila / Thana', 'Union']) && empty($v['upazila'])) {
            throw ValidationException::withMessages(['upazila' => 'An upazila or thana is required for this level.']);
        }
        if ($v['level'] === 'Union' && empty($v['union_name'])) {
            throw ValidationException::withMessages(['union_name' => 'A union is required for this level.']);
        }
        DB::transaction(function () use ($v, $referrals) {
            BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            $application = ! empty($v['application_id']) ? DealershipRequest::whereKey($v['application_id'])->lockForUpdate()->firstOrFail() : null;
            if ($application && Dealer::where('dealership_request_id', $application->id)->exists()) {
                throw ValidationException::withMessages(['application_id' => 'This application already has a dealer account.']);
            }
            $u = User::create(['name' => $v['name'], 'email' => strtolower($v['email']), 'password' => $v['password']]);
            $d = new Dealer(collect($v)->except(['name', 'email', 'password', 'referrer_code', 'referral_choice', 'referral_verified', 'application_id'])->all());
            $d->user_id = $u->id;
            $d->status = 'pending';
            $d->save();
            if ($application) {
                $d->forceFill(['dealership_request_id' => $application->id])->save();
                $application->forceFill(['status' => 'reviewed', 'reviewed_by' => auth()->id(), 'reviewed_at' => now(), 'review_note' => 'Dealer account created by the company.'])->save();
            }
            $code = $v['referrer_code'] ?? null;
            $referrals->assign($d->id, $code ?: null, 'Company-approved dealer onboarding');
            AuditEvent::record('dealer.created', ['status' => 'pending', 'dealer_type' => $d->fresh()->dealer_type], $d->id);
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
        if ($isCompany) {
            $validated += $r->validate(['dealer_type' => 'sometimes|required|in:dealer,area_dealer']);
        } else {
            $r->validate(['dealer_type' => 'prohibited']);
        }
        DB::transaction(function () use ($dealer, $validated, $isCompany): void {
            $record = Dealer::whereKey($dealer->id)->lockForUpdate()->firstOrFail();
            $previousType = $record->dealer_type;
            if ($isCompany) {
                $user = User::whereKey($record->user_id)->lockForUpdate()->firstOrFail();
                if ($user->email !== $validated['email']) {
                    DB::table('sessions')->where('user_id', $user->id)->delete();
                }
                $user->update(collect($validated)->only(['name', 'email'])->all());
            }
            $record->update(collect($validated)->except(['name', 'email'])->all());
            if ($previousType !== $record->dealer_type) {
                AuditEvent::record('dealer.type_changed', ['before' => $previousType, 'after' => $record->dealer_type], $record->id);
            }
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
