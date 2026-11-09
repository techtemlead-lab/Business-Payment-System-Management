<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Identity\BdrisLookup;
use App\Models\PlaceCode;
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
        $referenceWarnings = [];
        if ($reference !== '' && $own !== '' && $provisioning->isSameCode($reference, $own)) {
            $warnings[] = $referenceWarnings[] = __('portal.acct_val_reference_same_as_refer');
        }
        if ($own !== '' && $provisioning->referCodeInUse($own, openAccountsOnly: true)) {
            $warnings[] = __('portal.acct_val_refer_taken');
        }
        $management = ($reference !== '' && $provisioning->referenceIsManagement($reference))
            || ($reference === '' && $provisioning->blankReferenceUsesManagement());
        $sponsor = null;
        if ($reference !== '' && ! $management) {
            $sponsor = $provisioning->findSponsor($reference);
            if (! $sponsor) {
                $warnings[] = $referenceWarnings[] = __('portal.acct_val_reference_missing');
            }
        }

        return response()->json([
            'data' => [
                'warnings' => $warnings,
                'reference_warnings' => $referenceWarnings,
                'consumers' => $provisioning->commissionConsumers($sponsor, $management),
                'place_options_hint' => PlaceCode::query()->where('is_active', true)->count(),
            ],
        ]);
    }
}
