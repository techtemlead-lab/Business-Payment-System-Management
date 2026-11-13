<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerAccountDeletion;
use App\Models\User;
use App\Support\ReasonFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class PartnerAccountDeletionController extends Controller
{
    /** What deleting this partner would do, shown before the owner confirms. */
    public function show(Request $r, User $user, PartnerAccountDeletion $deletion): JsonResponse
    {
        $this->permit($r, $user);

        return response()->json($deletion->preview($user));
    }

    public function destroy(Request $r, User $user, PartnerAccountDeletion $deletion): RedirectResponse
    {
        $this->permit($r, $user);
        $data = $r->validate([
            'reason' => ReasonFields::rule('partner.account_delete', 1000, 5),
            'confirmation' => 'required|string|max:60',
        ]);

        $result = $deletion->remove($r->user(), $user, ReasonFields::text($data['reason'] ?? null), $data['confirmation']);

        if ($result['outcome'] === PartnerAccountDeletion::OutcomeDeleted) {
            return back()->with('success', __('portal.flash_partner_deleted', ['code' => $result['dealer_code']]));
        }

        return back()->with('success', __('portal.flash_partner_closed', [
            'code' => $result['dealer_code'],
            'records' => collect($result['records'])->map(fn (array $record): string => $record['label'].': '.$record['count'])->implode(', '),
        ]));
    }

    private function permit(Request $r, User $account): void
    {
        abort_unless(PartnerAccountDeletion::canUse($r->user()) && PartnerAccountDeletion::isPartner($account), 403);
    }
}
