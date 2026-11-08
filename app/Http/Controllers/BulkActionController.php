<?php

namespace App\Http\Controllers;

use App\Domain\Lists\BulkActions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs one of the few safe actions on rows picked from a list. Any other action name, such as
 * approving a payout, is refused here: there is no way to reach money through this route.
 */
class BulkActionController extends Controller
{
    public function __invoke(Request $request, string $action, BulkActions $bulk): Response|JsonResponse|View
    {
        abort_unless(in_array($action, BulkActions::Allowed, true), 403, __('productivity.bulk_not_allowed'));
        $user = $request->user();
        $idRule = in_array($action, [BulkActions::OrdersExport, BulkActions::OrderSlips], true) ? 'uuid' : 'integer|min:1';
        $ids = $request->validate(['ids' => 'required|array|min:1|max:'.BulkActions::MaxItems, 'ids.*' => 'required|'.$idRule])['ids'];

        return match ($action) {
            BulkActions::OrdersExport => $bulk->exportOrders($user, $request, array_map(strval(...), $ids)),
            BulkActions::PartnersExport => $bulk->exportPartners($user, $request, array_map(intval(...), $ids)),
            BulkActions::OrderSlips => view('bulk.order-slips', ['orders' => $bulk->orders($user, $request, array_map(strval(...), $ids))]),
            default => response()->json(['data' => ['unread' => $bulk->markNoticesRead($user, array_map(intval(...), $ids))]]),
        };
    }
}
