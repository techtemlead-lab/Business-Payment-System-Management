<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\IntegrationSettings;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class IntegrationSettingsController extends Controller
{
    public function index(Request $request, IntegrationSettings $integrations): Response
    {
        $actor = $request->user();
        abort_unless($actor && ($actor->canAct('finance', 'view') || $actor->role === 'super_admin'), 403);
        $data = [
            'integrations' => $integrations->snapshot(false),
            'startup' => $integrations->startupMode(),
            'canEdit' => $actor->canAct('finance', 'edit') || $actor->role === 'super_admin',
        ];

        return $request->expectsJson()
            ? response()->json(['data' => $data])
            : Inertia::render('ServiceIntegrations', $data)->toResponse($request);
    }

    public function saveProvider(Request $request, IntegrationSettings $integrations): Response
    {
        $data = $request->validate([
            'group' => 'required|string|max:40',
            'provider' => 'required|string|max:40',
            'version' => 'required|integer|min:0',
            'reason' => FinanceOperationsController::Reason,
            'enabled' => 'required|boolean',
            'base_url' => 'nullable|string|max:255',
            'api_key' => 'nullable|string|max:500',
            'api_secret' => 'nullable|string|max:500',
            'app_key' => 'nullable|string|max:500',
            'app_secret' => 'nullable|string|max:500',
            'username' => 'nullable|string|max:200',
            'password' => 'nullable|string|max:500',
            'client_secret' => 'nullable|string|max:500',
            'store_password' => 'nullable|string|max:500',
            'store_id' => 'nullable|string|max:120',
            'sender_id' => 'nullable|string|max:40',
            'user_id' => 'nullable|string|max:120',
            'timeout' => 'nullable|integer|min:3|max:60',
            'mode' => 'nullable|string|in:sandbox,live',
            'map_embed_url' => 'nullable|string|max:1000',
        ]);
        $integrations->saveProvider(
            $request->user(),
            $data['group'],
            $data['provider'],
            $data,
            (int) $data['version'],
            $data['reason']
        );

        return $this->done($request);
    }

    public function saveStartup(Request $request, IntegrationSettings $integrations): Response
    {
        $data = $request->validate([
            'mode' => 'required|in:fresh,import',
            'version' => 'required|integer|min:0',
            'reason' => FinanceOperationsController::Reason,
        ]);
        $integrations->saveStartupMode($request->user(), $data['mode'], (int) $data['version'], $data['reason']);

        return $this->done($request);
    }

    /** @param  array<string, mixed>  $data */
    private function done(Request $request, array $data = []): Response
    {
        return $request->expectsJson()
            ? response()->json(['data' => $data])
            : back()->with('success', 'Changes recorded successfully.');
    }
}
