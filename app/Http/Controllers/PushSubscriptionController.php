<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\WebPushNotices;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Turns phone/browser notifications on or off for the signed-in user on this browser.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request, WebPushNotices $push): JsonResponse
    {
        abort_unless(WebPushNotices::enabled(), 404);
        /** @var array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding?: string|null} $data */
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:1024'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['nullable', 'string', 'in:aesgcm,aes128gcm'],
        ]);
        $push->subscribe($request, $request->user(), $data);

        return response()->json(['subscribed' => true]);
    }

    public function destroy(Request $request, WebPushNotices $push): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:1024'],
        ]);
        $push->unsubscribe($request, $request->user(), $data['endpoint']);

        return response()->json(['subscribed' => false]);
    }
}
