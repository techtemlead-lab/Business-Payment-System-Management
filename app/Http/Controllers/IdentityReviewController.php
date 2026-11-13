<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\DuplicateIdentityScan;
use App\Domain\Accounts\IdentityReviewList;
use App\Support\ReasonFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The review list of possible duplicate accounts. Flag only: nothing here blocks
 * an account or changes a commission.
 */
class IdentityReviewController extends Controller
{
    public function __construct(private readonly IdentityReviewList $list) {}

    public function index(Request $request): JsonResponse
    {
        abort_unless(IdentityReviewList::mayReview($request->user()), 403);
        $data = $request->validate([
            'status' => ['nullable', Rule::in(IdentityReviewList::Statuses)],
            'kind' => ['nullable', Rule::in(DuplicateIdentityScan::Kinds)],
            'page' => 'nullable|integer|min:1',
        ]);

        return response()->json(['data' => $this->list->page($data['status'] ?? 'open', $data['kind'] ?? null, (int) ($data['page'] ?? 1))])
            ->header('Cache-Control', 'private, no-store');
    }

    public function scan(Request $request, DuplicateIdentityScan $scan): JsonResponse
    {
        abort_unless(IdentityReviewList::mayReview($request->user()), 403);

        return response()->json(['data' => $scan->run()]);
    }

    public function mark(Request $request, int $flag): JsonResponse
    {
        abort_unless(IdentityReviewList::mayReview($request->user()), 403);
        // The note box is only shown when a flag is cleared; opening one again asks for nothing.
        $noteRule = $request->input('status') === 'cleared' ? ReasonFields::rule('partner.identity_review', 300) : ['nullable', 'string', 'max:300'];
        $data = $request->validate(['status' => ['required', Rule::in(['open', 'cleared'])], 'note' => $noteRule]);
        $this->list->mark($request->user(), $flag, $data['status'], $data['note'] ?? null);

        return response()->json(['message' => __('productivity.review_saved')]);
    }
}
