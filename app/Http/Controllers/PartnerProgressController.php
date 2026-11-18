<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerProgress;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "My progress" pop-up on the dealer and sub-dealer home screens. Loaded only when the
 * partner opens the pop-up, so the home screen itself stays as quick as before.
 */
class PartnerProgressController extends Controller
{
    public function __construct(private PartnerProgress $progress) {}

    public function show(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);
        // A typed or bookmarked address would show raw JSON; the pop-up lives on the dashboard, so go there.
        if (! $request->expectsJson() && ! $request->ajax()) {
            return redirect('/');
        }

        return response()->json(
            ['data' => $this->progress->for($user)],
            200,
            ['Cache-Control' => 'private, no-store'],
        );
    }
}
