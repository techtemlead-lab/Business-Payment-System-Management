<?php

namespace App\Domain\Notifications;

use App\Models\User;
use App\Support\LocalDigits;
use App\Support\PortalHost;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * The email behind "forgot password" and the set-your-password invitation. Its text comes from the
 * message templates, so the owner can re-word it; the default is plain Bangla (or English for a
 * person who chose English).
 */
final class PasswordResetMail
{
    public static function url(mixed $user, string $token): string
    {
        $portal = $user instanceof User ? PortalHost::forUser($user) : 'dealer';

        return PortalHost::url($portal, '/reset-password/'.$token.'?email='.urlencode((string) data_get($user, 'email')));
    }

    public static function build(mixed $notifiable, string $token): MailMessage
    {
        $locale = $notifiable instanceof User ? $notifiable->preferredLocale() : SmsText::Locale;
        $link = self::url($notifiable, $token);
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);
        $company = (string) (config('mail.from.name') ?: config('app.name'));
        $default = MessageTemplateCatalog::defaultSource(MessageTemplateCatalog::ResetLinkKey, MessageTemplateCatalog::Email, $locale);
        $values = ['name' => (string) data_get($notifiable, 'name'), 'link' => $link, 'company' => $company, 'minutes' => LocalDigits::text($minutes, $locale)];
        $filled = static fn (string $text): string => (string) MessageTemplates::fill($text, $values, MessageTemplateCatalog::placeholdersOf(MessageTemplateCatalog::ResetLinkKey, MessageTemplateCatalog::Email));
        $text = MessageTemplates::email(
            MessageTemplateCatalog::ResetLinkKey,
            $locale,
            $values,
            $filled((string) $default['subject']),
            $filled($default['body']),
        );

        // The view carries the wording; action() only keeps the link on the message, as Laravel's own reset mail does.
        return (new MailMessage)
            ->subject($text['subject'])
            ->action((string) __('message_templates.reset_button', [], $locale), $link)
            ->view(['mail.event-notice', 'mail.event-notice-text'], [
                'noticeTitle' => $text['subject'],
                'noticeBody' => $text['body'],
                'recipientName' => $values['name'],
                'portalUrl' => $link,
                'buttonLabel' => (string) __('message_templates.reset_button', [], $locale),
                // The mail is built outside a request in the person's language, so the fixed lines are worded here.
                'greeting' => (string) __('event_emails.greeting', ['name' => $values['name']], $locale),
                'footer' => (string) __('event_emails.footer', ['company' => $company], $locale),
            ]);
    }
}
