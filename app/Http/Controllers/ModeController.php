<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\RankDefinition;
use App\Services\ModeManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ModeController extends WorkspaceController
{
    public function mode(Request $r): Response
    {
        $this->admin($r);

        return Inertia::render('Mode', ['ranks' => RankDefinition::latest('id')->get(), 'rankReady' => RankDefinition::where('status', 'approved')->exists()]);
    }

    public function changeMode(Request $r, ModeManager $m): RedirectResponse
    {
        $this->admin($r);
        $this->stepUp($r);
        $v = $r->validate(['mode' => 'required|in:COMMISSION_BASED,RANK_BASED', 'revision' => 'required|integer', 'reason' => 'required|string|min:5|max:500']);
        $m->change($v['mode'], (int) $v['revision'], $v['reason']);

        return back()->with('success', 'Operating mode updated. Historical records are unchanged.');
    }

    public function createRank(Request $r): RedirectResponse
    {
        $this->admin($r);
        $v = $r->validate(['name' => 'required|string|max:100|unique:rank_definitions,name', 'eligibility' => 'required|string|min:10|max:1000']);
        DB::transaction(function () use ($v) {
            $rank = RankDefinition::create([...$v, 'actor_id' => auth()->id(), 'effect' => 'status_only', 'status' => 'draft']);
            AuditEvent::record('rank.created', ['rank_id' => $rank->id]);
        });

        return back()->with('success', 'Rank definition saved as a draft.');
    }

    public function approveRank(Request $r, RankDefinition $rank): RedirectResponse
    {
        $this->admin($r);
        $this->stepUp($r);
        DB::transaction(function () use ($rank) {
            $rank->update(['status' => 'approved']);
            AuditEvent::record('rank.approved', ['rank_id' => $rank->id, 'effect' => 'status_only']);
        });

        return back()->with('success', 'Status-only rank approved. Automated rank assignment and financial rewards remain inactive.');
    }
}
