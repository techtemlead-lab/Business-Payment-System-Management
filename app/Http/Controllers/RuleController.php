<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\RuleVersion;
use App\Services\FinancialCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class RuleController extends WorkspaceController
{
    public function rules(Request $r, FinancialCalculator $c): Response
    {
        $this->admin($r);

        return Inertia::render('Rules', ['versions' => RuleVersion::latest('id')->limit(20)->get(), 'preview' => $c->preview(20000)]);
    }

    public function preview(Request $r, FinancialCalculator $c): JsonResponse
    {
        $this->admin($r);
        $r->validate(['mrp' => 'required|string|max:12', 'version_id' => 'nullable|integer|exists:rule_versions,id']);
        $version = $r->filled('version_id') ? RuleVersion::findOrFail($r->integer('version_id')) : null;

        return response()->json([...$c->preview($c->parseMoney($r->mrp), $version?->definition), 'version_id' => $version?->id]);
    }

    public function saveRule(Request $r, FinancialCalculator $c): RedirectResponse
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:100', 'company_bps' => 'required|integer|min:0|max:10000', 'dealer_bps' => 'required|integer|min:0|max:10000', 'pool_bps' => 'required|integer|min:0|max:10000', 'reason' => 'required|string|min:10|max:500', 'effective_from' => 'required|date', 'effective_to' => 'nullable|date|after_or_equal:effective_from']);
        $d = ['company_bps' => (int) $v['company_bps'], 'dealer_bps' => (int) $v['dealer_bps'], 'pool_bps' => (int) $v['pool_bps'], 'rewards' => FinancialCalculator::REWARDS];
        $c->preview(20000, $d);
        DB::transaction(function () use ($v, $d) {
            $version = RuleVersion::create(['name' => $v['name'], 'status' => 'draft', 'definition' => $d, 'reason' => $v['reason'], 'effective_from' => $v['effective_from'], 'effective_to' => $v['effective_to'] ?? null, 'actor_id' => auth()->id()]);
            AuditEvent::record('rule.draft_created', ['version_id' => $version->id]);
        });

        return back()->with('success', 'Immutable draft version saved. Financial posting remains inactive.');
    }
}
