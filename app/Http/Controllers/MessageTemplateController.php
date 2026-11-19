<?php

namespace App\Http\Controllers;

use App\Domain\Notifications\MessageTemplateCatalog;
use App\Domain\Notifications\MessageTemplates;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Saves or resets the owner-edited SMS and email texts. The owner and staff who may edit the
 * website (EnforceStaffActions checks the staff right; this controller checks again).
 */
class MessageTemplateController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $actor = $this->editor($request);
        $data = $this->validated($request) + $request->validate([
            'subject' => 'nullable|string|max:'.MessageTemplates::EmailSubjectMaxLength,
            'body' => 'required|string|max:'.MessageTemplates::EmailBodyMaxLength,
        ]);
        MessageTemplates::save($actor, $data['key'], $data['channel'], $data['locale'], $data['subject'] ?? null, $data['body']);

        return back()->with('success', __('message_templates.flash_saved'));
    }

    public function reset(Request $request): RedirectResponse
    {
        $actor = $this->editor($request);
        $data = $this->validated($request);
        MessageTemplates::reset($actor, $data['key'], $data['channel'], $data['locale']);

        return back()->with('success', __('message_templates.flash_reset'));
    }

    private function editor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && ($actor->owner() || $actor->canAct('content', 'edit')), 403);

        return $actor;
    }

    /** @return array{key: string, channel: string, locale: string} */
    private function validated(Request $request): array
    {
        return $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'channel' => ['required', Rule::in([MessageTemplateCatalog::Sms, MessageTemplateCatalog::Email])],
            'locale' => ['required', Rule::in(MessageTemplateCatalog::Locales)],
        ]);
    }
}
