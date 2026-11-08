<?php

namespace App\Http\Controllers;

use App\Domain\Security\UserDevices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The device list on the security page. Everyone sees only their own devices. Logging one
 * out asks for the password, the same as "log out other devices" does.
 */
class DeviceSessionController extends Controller
{
    public function __construct(private readonly UserDevices $devices) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->devices->forUser($request->user(), $request->session()->getId())])
            ->header('Cache-Control', 'private, no-store');
    }

    public function logOut(Request $request, string $handle): JsonResponse
    {
        $request->validate(['current_password' => 'required|current_password']);
        abort_unless($this->devices->logOut($request->user(), $handle, $request->session()->getId()), 404);

        return response()->json(['data' => $this->devices->forUser($request->user(), $request->session()->getId())]);
    }
}
