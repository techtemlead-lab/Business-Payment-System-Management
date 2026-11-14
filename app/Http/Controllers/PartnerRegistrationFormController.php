<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerRegistrationForm;
use App\Models\User;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The printable registration form of one dealer or sub-dealer. Only staff who may manage
 * accounts can download it; the identity number is printed in full only for staff who may write it.
 */
class PartnerRegistrationFormController extends Controller
{
    public function __invoke(Request $request, User $user, PartnerRegistrationForm $form): BinaryFileResponse
    {
        $staff = $request->user();
        abort_unless($staff->staff() || $staff->usingStaffWorkspace(), 403);
        abort_unless($staff->allows('accounts'), 403);
        $data = $request->validate(['lang' => 'nullable|in:en,bn']);

        return $form->download($staff, $user, $data['lang'] ?? null);
    }
}
