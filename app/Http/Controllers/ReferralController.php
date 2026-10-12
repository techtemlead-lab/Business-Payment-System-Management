<?php

namespace App\Http\Controllers;

use App\Models\Dealer;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ReferralController extends WorkspaceController
{
    public function options(Request $request): JsonResponse
    {
        $this->admin($request);
        $validated = $request->validate(['q' => 'nullable|string|max:80', 'exclude' => 'nullable|integer|exists:dealers,id']);
        $query = Dealer::query()->with('user:id,name');
        if (! empty($validated['q'])) {
            $term = '%'.trim($validated['q']).'%';
            $query->where(fn ($query) => $query->whereLike('code', $term)->orWhereHas('user', fn ($user) => $user->whereLike('name', $term)));
        }
        if (! empty($validated['exclude'])) {
            $query->where('id', '!=', $validated['exclude']);
        }

        return response()->json(['dealers' => $query->orderBy('code')->limit(20)->get(['id', 'user_id', 'code', 'status'])->map(fn (Dealer $dealer): array => ['code' => $dealer->code, 'name' => $dealer->user->name, 'status' => $dealer->status])]);
    }

    public function index(Request $request, ReferralService $referrals): Response
    {
        $request->validate(['code' => 'nullable|string|max:30', 'generation' => 'nullable|integer|between:1,3']);
        $admin = $request->user()->role === 'admin';
        if ($admin) {
            $this->admin($request);
            $root = $request->filled('code') ? Dealer::where('code', strtoupper(trim($request->input('code'))))->firstOrFail() : null;
        } else {
            $root = $request->user()->dealer;
        }
        $counts = [0, 0, 0];
        $generation = $request->integer('generation', 1);
        $query = Dealer::query();
        if ($root) {
            $parents = Dealer::whereKey($root->id)->select('id');
            for ($level = 1; $level <= 3; $level++) {
                $children = Dealer::whereIn('referrer_id', clone $parents);
                $counts[$level - 1] = (clone $children)->count();
                if ($level === $generation) {
                    $query = clone $children;
                }
                $parents = $children->select('id');
            }
        }
        $rows = $query->with('user:id,name', 'referrer:id,user_id,code', 'referrer.user:id,name', 'referralVerifier:id,name')->latest('id')->paginate(15)->withQueryString()->through(fn (Dealer $dealer): array => ['id' => $dealer->id, 'name' => $dealer->user->name, 'code' => $dealer->code, 'status' => $dealer->status, 'referrer_code' => $dealer->referrer?->code, 'referrer_name' => $dealer->referrer?->user->name, 'referral_verified_at' => $dealer->referral_verified_at, 'referral_verified_by_name' => $dealer->referralVerifier?->name, 'created_at' => $dealer->created_at]);

        return Inertia::render('Referrals', ['root' => $root?->only(['id', 'code']), 'counts' => $counts, 'generation' => $generation, 'dealers' => $rows, 'ancestors' => $root ? $referrals->ancestors($root) : [], 'referralLink' => $root ? url('/request-dealership').'?ref='.rawurlencode($root->code) : null]);
    }

    public function update(Request $request, Dealer $dealer, ReferralService $referrals): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        $request->merge(['referrer_code' => strtoupper(trim((string) $request->input('referrer_code')))]);
        $validated = $request->validate(['referral_choice' => 'sometimes|required|in:none,referred', 'referrer_code' => 'required_if:referral_choice,referred|prohibited_if:referral_choice,none|nullable|string|max:30', 'referral_verified' => [Rule::requiredIf($request->filled('referrer_code')), Rule::when($request->filled('referrer_code'), ['accepted']), 'nullable', 'boolean'], 'reason' => 'required|string|min:5|max:500']);
        $referrals->assign($dealer->id, $validated['referrer_code'] ?? null, $validated['reason']);

        return back()->with('success', 'Referrer updated. Historical purchase referral snapshots remain unchanged.');
    }
}
