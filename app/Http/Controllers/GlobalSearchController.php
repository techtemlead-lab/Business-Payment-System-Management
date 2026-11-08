<?php

namespace App\Http\Controllers;

use App\Domain\Search\GlobalSearch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Answers the office portal search box. It runs only when someone types, so opening
 * a page never costs extra queries.
 */
class GlobalSearchController extends Controller
{
    public function __invoke(Request $request, GlobalSearch $search): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->staff() || $user->usingStaffWorkspace(), 403);
        $data = $request->validate(['q' => 'required|string|min:2|max:80']);

        return response()->json(['data' => $search->run($user, $request, $data['q'])])
            ->header('Cache-Control', 'private, no-store');
    }
}
