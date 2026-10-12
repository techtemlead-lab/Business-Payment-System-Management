<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDealershipRequest;
use App\Models\AuditEvent;
use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Services\WebsiteService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DealershipRequestController extends WorkspaceController
{
    public function create(): Response
    {
        return Inertia::render('RequestDealership');
    }

    public function store(StoreDealershipRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $validated = $request->safe()->except(['consent', 'language']);
            $definitions = app(WebsiteService::class)->published()['application']['questions'];
            $answers = [];
            foreach ($definitions as $definition) {
                if (isset($validated['additional_answers'][$definition['id']])) {
                    $answers[] = ['label' => $definition['label'], 'value' => $validated['additional_answers'][$definition['id']]];
                }
            }
            $validated['additional_answers'] = $answers;
            $application = DealershipRequest::create($validated);
            if (! empty($validated['referral_code'])) {
                $application->forceFill(['referrer_id' => Dealer::where('code', $validated['referral_code'])->firstOrFail()->id])->save();
            }
            AuditEvent::record('dealership.request_received', ['level' => $application->level], $application->id);
        });

        $document = app(WebsiteService::class)->published();
        $message = $request->input('language') === 'bn' ? ($document['translations']['application.success_message'] ?? $document['application']['success_message']) : $document['application']['success_message'];

        return redirect('/request-dealership')->with('success', $message);
    }

    public function index(Request $request): Response
    {
        $this->admin($request);
        $request->validate(['q' => 'nullable|string|max:80', 'status' => 'nullable|in:pending,reviewed,declined,all']);
        $status = $request->input('status', 'pending');
        $query = DealershipRequest::query();
        if ($status !== 'all') {
            $query->where('status', $status);
        }
        if ($request->filled('q')) {
            $term = '%'.$request->input('q').'%';
            $query->where(fn ($query) => $query->whereLike('name', $term)->orWhereLike('business_name', $term)->orWhereLike('email', $term));
        }

        return Inertia::render('DealershipRequests', [
            'requests' => $query->latest('id')->paginate(12)->withQueryString(),
            'filters' => ['q' => $request->input('q', ''), 'status' => $status],
            'counts' => DealershipRequest::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->pluck('total', 'status'),
        ]);
    }

    public function update(Request $request, DealershipRequest $dealershipRequest): RedirectResponse
    {
        $this->admin($request);
        $this->stepUp($request);
        $validated = $request->validate(['status' => 'required|in:pending,reviewed,declined', 'reason' => 'required|string|min:5|max:500']);
        DB::transaction(function () use ($request, $dealershipRequest, $validated): void {
            $application = DealershipRequest::whereKey($dealershipRequest->id)->lockForUpdate()->firstOrFail();
            $before = $application->status;
            $application->forceFill([
                'status' => $validated['status'], 'review_note' => $validated['reason'],
                'reviewed_by' => $request->user()->id, 'reviewed_at' => now(),
            ])->save();
            AuditEvent::record('dealership.request_reviewed', ['before' => $before, 'after' => $application->status, 'reason' => $validated['reason']], $application->id);
        });

        return back()->with('success', 'Request review saved. Dealer accounts are created separately by the company.');
    }
}
