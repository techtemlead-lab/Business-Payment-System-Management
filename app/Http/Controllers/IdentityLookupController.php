<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Identity\BdrisLookup;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IdentityLookupController extends Controller
{
    public function bdris(Request $request, BdrisLookup $lookup): JsonResponse
    {
        abort_unless($request->user()?->allows('accounts'), 403);
        $data = $request->validate([
            'birth_certificate_number' => 'required|string|max:40',
            'date_of_birth' => 'required|date',
        ]);

        return response()->json(['data' => $lookup->fetch($data['birth_certificate_number'], $data['date_of_birth'])]);
    }

    public function placePreview(Request $request, PartnerAccountProvisioning $provisioning): JsonResponse
    {
        abort_unless($request->user()?->allows('accounts'), 403);
        $data = $request->validate([
            'place_code' => 'required|string|max:32',
            'role' => 'required|in:dealer,sub_dealer',
        ]);

        return response()->json([
            'data' => $provisioning->resolvePlacePreview($data['place_code'], $data['role']),
        ]);
    }

    public function referPreview(Request $request, PartnerAccountProvisioning $provisioning): JsonResponse
    {
        abort_unless($request->user()?->allows('accounts'), 403);
        $data = $request->validate([
            'reference_code' => 'nullable|string|max:40',
            'refer_code' => 'nullable|string|max:40',
        ]);
        $reference = strtoupper(trim((string) ($data['reference_code'] ?? '')));
        $own = strtoupper(trim((string) ($data['refer_code'] ?? '')));
        $warnings = [];
        if ($reference !== '' && $own !== '' && $reference === $own) {
            $warnings[] = __('portal.acct_val_reference_same_as_refer');
        }
        if ($own !== '' && User::query()->whereRaw('UPPER(refer_code) = ?', [$own])->exists()) {
            $warnings[] = __('portal.acct_val_refer_taken');
        }
        $sponsor = null;
        if ($reference !== '') {
            $sponsor = User::query()
                ->whereRaw('UPPER(refer_code) = ?', [$reference])
                ->where('role', 'dealer')
                ->where('status', 'active')
                ->first();
            if (! $sponsor) {
                $warnings[] = __('portal.acct_val_reference_missing');
            }
        }

        return response()->json([
            'data' => [
                'warnings' => $warnings,
                'consumers' => $provisioning->commissionConsumers($sponsor),
                'place_options_hint' => PlaceCode::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
