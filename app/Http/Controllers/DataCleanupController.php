<?php

namespace App\Http\Controllers;

use App\Domain\Security\DataCleanup;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The owner deletes chosen old login codes, SMS records and closed enquiries. Nothing is deleted automatically.
 */
class DataCleanupController extends Controller
{
    public function destroy(Request $request, DataCleanup $cleanup): RedirectResponse|JsonResponse
    {
        abort_unless(DataCleanup::canUse($request->user()), 403);
        $refs = $request->validate([
            'refs' => 'required|array|min:1|max:'.DataCleanup::MaxPerDelete,
            'refs.*' => 'required|string|max:300|distinct',
            'confirm' => 'accepted',
        ])['refs'];
        $deleted = $cleanup->delete($request->user(), array_values(array_map('strval', $refs)));
        $total = array_sum($deleted);

        return $request->expectsJson()
            ? response()->json(['data' => ['deleted' => $deleted, 'total' => $total]])
            : back()->with('success', __('portal.cleanup_flash_deleted', ['count' => (string) $total]));
    }
}
