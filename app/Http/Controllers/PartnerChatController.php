<?php

namespace App\Http\Controllers;

use App\Domain\Support\ChatRouting;
use App\Domain\Support\PartnerChat;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Chat between partners and the office: partners send to the office, the office replies to one partner.
 */
class PartnerChatController extends Controller
{
    public function send(Request $request, PartnerChat $chat): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:'.PartnerChat::MaxBody,
            'photo' => PartnerChat::AttachmentRules,
        ]);
        $message = $chat->sendFromPartner($request->user(), $data['body'] ?? null, $request->file('photo'));

        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $message->id]], 201)
            : back();
    }

    public function reply(Request $request, User $user, PartnerChat $chat): RedirectResponse|JsonResponse
    {
        $data = $request->validate([
            'body' => 'nullable|string|max:'.PartnerChat::MaxBody,
            'photo' => PartnerChat::AttachmentRules,
        ]);
        $message = $chat->replyFromOffice($request->user(), $user, $data['body'] ?? null, $request->file('photo'));

        // Stay in the same chat after sending (back() would land on the inbox).
        return $request->expectsJson()
            ? response()->json(['data' => ['id' => $message->id]], 201)
            : redirect('/partner-messages?with='.$user->id);
    }

    /**
     * The owner's list of staff who answer partner chat, each for their own branches or the whole company.
     */
    public function answerers(Request $request, ChatRouting $routing): RedirectResponse|JsonResponse
    {
        abort_unless($request->user()->owner(), 403);
        $data = $request->validate([
            'answerers' => 'present|array|max:200',
            'answerers.*.user_id' => 'required|integer|distinct',
            'answerers.*.scope' => 'required|in:'.implode(',', ChatRouting::Scopes),
            'version' => 'required|integer|min:0',
        ]);
        $routing->save($request->user(), $data['answerers'], (int) $data['version']);

        return $request->expectsJson()
            ? response()->json(['data' => ['saved' => true]])
            : back()->with('success', __('portal.chat_route_saved'));
    }

    public function photo(Request $request, PartnerMessage $message, PartnerChat $chat): StreamedResponse
    {
        $message = $chat->attachmentFor($request->user(), $message);

        return Storage::disk('local')->response($message->attachment_path, null, [
            'Content-Type' => $message->attachment_mime ?? 'image/jpeg',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }
}
