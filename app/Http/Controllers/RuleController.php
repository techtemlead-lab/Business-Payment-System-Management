<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\BusinessConfig;
use App\Models\Purchase;
use App\Models\RuleVersion;
use App\Services\FinancialCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends WorkspaceController
{
    public function rules(Request $r): Response
    {
        $this->admin($r);

        $config = BusinessConfig::findOrFail(1);
        $active = $config->active_rule_version_id ? RuleVersion::findOrFail($config->active_rule_version_id) : null;

        return Inertia::render('Rules', ['versions' => RuleVersion::latest('id')->limit(20)->get(), 'activeVersionId' => $active?->id, 'activeDefinition' => $active?->definition ?? FinancialCalculator::definition(), 'ruleRevision' => $config->rule_revision, 'preview' => null, 'purchaseTotals' => Purchase::allocationTotals()]);
    }

    public function preview(Request $r, FinancialCalculator $c): JsonResponse
    {
        $this->admin($r);
        $r->validate(['mrp' => 'required|string|max:12', 'version_id' => 'nullable|integer|exists:rule_versions,id']);
        $active = BusinessConfig::findOrFail(1)->active_rule_version_id;
        $version = $r->filled('version_id') ? RuleVersion::findOrFail($r->integer('version_id')) : ($active ? RuleVersion::findOrFail($active) : null);

        return response()->json([...$c->preview($c->parseMoney($r->mrp), $version?->definition), 'version_id' => $version?->id]);
    }

    public function saveRule(Request $r, FinancialCalculator $c): RedirectResponse
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:100', 'company_bps' => 'required|integer|min:0|max:10000', 'dealer_bps' => 'required|integer|min:0|max:10000', 'pool_bps' => 'required|integer|min:0|max:10000', 'reason' => 'required|string|min:10|max:500', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from']);
        $extra = $r->validate(['rewards' => 'nullable|array:'.implode(',', array_keys(FinancialCalculator::REWARDS)), 'rewards.*' => 'required|integer|min:0|max:10000', 'referral_bps' => 'nullable|array|list|size:3', 'referral_bps.*' => 'required|integer|min:0|max:10000']);
        $d = ['company_bps' => (int) $v['company_bps'], 'dealer_bps' => (int) $v['dealer_bps'], 'pool_bps' => (int) $v['pool_bps'], 'rewards' => isset($extra['rewards']) ? array_replace(FinancialCalculator::REWARDS, $extra['rewards']) : FinancialCalculator::REWARDS, 'referrals' => ['base' => 'pending_discussion', 'generation_bps' => $extra['referral_bps'] ?? [0, 0, 0], 'posting_enabled' => false]];
        $c->preview(0, $d);
        DB::transaction(function () use ($v, $d) {
            $version = RuleVersion::create(['name' => $v['name'], 'status' => 'draft', 'definition' => $d, 'reason' => $v['reason'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?? null, 'actor_id' => auth()->id()]);
            AuditEvent::record('rule.draft_created', ['version_id' => $version->id]);
        });

        return back()->with('success', 'Commission and reward percentages saved as a new draft. Referral payouts remain inactive until the calculation base is confirmed.');
    }

    public function activate(Request $request, RuleVersion $version, FinancialCalculator $calculator): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        $request->validate(['revision' => 'required|integer', 'reason' => 'required|string|min:5|max:500']);
        $calculator->preview(0, $version->definition);
        if (($version->effective_from && $version->effective_from > now()->toDateString()) || ($version->effective_to && $version->effective_to < now()->toDateString())) {
            throw ValidationException::withMessages(['version' => 'This rule version is outside its effective date range.']);
        }
        DB::transaction(function () use ($request, $version): void {
            $config = BusinessConfig::query()->lockForUpdate()->findOrFail(1);
            if ((int) $config->rule_revision !== $request->integer('revision')) {
                throw ValidationException::withMessages(['revision' => 'The active commission version changed. Reload before activating.']);
            }
            $before = $config->active_rule_version_id;
            $config->update(['active_rule_version_id' => $version->id, 'rule_revision' => $config->rule_revision + 1]);
            AuditEvent::record('rule.activated', ['before' => $before, 'after' => $version->id, 'reason' => $request->input('reason'), 'referral_posting_enabled' => false]);
        });

        return back()->with('success', 'Rule version activated for future purchase entries. Previous purchases and earnings are unchanged. Referral payouts remain inactive.');
    }
}
