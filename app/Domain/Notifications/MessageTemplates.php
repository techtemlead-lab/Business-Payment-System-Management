<?php

namespace App\Domain\Notifications;

use App\Models\MessageTemplate;
use App\Models\User;
use App\Support\Audit;
use App\Support\RedactedError;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The one place every SMS and email text is built. The default is today's text in the language
 * files; a saved row only overrides it. A missing, switched-off or broken override never stops a
 * send: the sender gets the default text instead.
 */
final class MessageTemplates
{
    private const CacheKey = 'message_templates:overrides';

    public const SmsMaxLength = 480;

    public const EmailSubjectMaxLength = 150;

    public const EmailBodyMaxLength = 4000;

    /**
     * An SMS: the saved text when there is a good one, else the Bangla default from the language files.
     * Always Bangla, like every SMS (SmsText).
     *
     * @param  array<string, mixed>  $replace
     */
    public static function sms(string $key, array $replace): string
    {
        $override = self::override($key, MessageTemplateCatalog::Sms, SmsText::Locale);
        if ($override instanceof MessageTemplate) {
            $text = self::fill((string) $override->body, $replace, MessageTemplateCatalog::placeholdersOf($key, MessageTemplateCatalog::Sms));
            if ($text !== null && trim($text) !== '') {
                return $text;
            }
            Log::warning('message_template.fell_back', ['key' => $key, 'channel' => 'sms']);
        }

        return (string) __($key, $replace, SmsText::Locale);
    }

    /**
     * An email's title and body. When there is no good override, the text the sender prepared comes back untouched.
     *
     * @param  array<string, mixed>  $values  name, link, company, notice_title, notice_text and any event extras
     * @return array{subject: string, body: string}
     */
    public static function email(string $key, string $locale, array $values, string $defaultSubject, string $defaultBody): array
    {
        $override = self::override($key, MessageTemplateCatalog::Email, $locale);
        if ($override instanceof MessageTemplate) {
            $allowed = MessageTemplateCatalog::placeholdersOf($key, MessageTemplateCatalog::Email);
            $subject = self::fill((string) ($override->subject ?? ''), $values, $allowed);
            $body = self::fill((string) $override->body, $values, $allowed);
            if ($subject !== null && $body !== null && trim($subject) !== '' && trim($body) !== '') {
                return ['subject' => $subject, 'body' => $body];
            }
            Log::warning('message_template.fell_back', ['key' => $key, 'channel' => 'email']);
        }

        return ['subject' => $defaultSubject, 'body' => $defaultBody];
    }

    /**
     * Puts the values in. A mark that is not an allowed placeholder, or has no value, makes the text
     * unusable (null) so the caller can fall back to the default.
     *
     * @param  array<string, mixed>  $values
     * @param  list<string>  $allowed
     */
    public static function fill(string $template, array $values, array $allowed): ?string
    {
        $broken = false;
        $text = (string) preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', function (array $match) use ($values, $allowed, &$broken): string {
            $name = $match[1];
            if (! in_array($name, $allowed, true) || ! array_key_exists($name, $values)) {
                $broken = true;

                return $match[0];
            }

            return (string) $values[$name];
        }, $template);

        return $broken ? null : $text;
    }

    /** The saved, switched-on text for one template, or null. Never throws. */
    public static function override(string $key, string $channel, string $locale): ?MessageTemplate
    {
        try {
            $all = Cache::remember(self::CacheKey, 300, fn (): array => MessageTemplate::query()->where('active', true)->get()->all());
            foreach ($all as $row) {
                if ($row->key === $key && $row->channel === $channel && $row->locale === $locale) {
                    return $row;
                }
            }
        } catch (\Throwable $exception) {
            Log::warning('message_template.read_failed', ['error' => RedactedError::message($exception)]);
        }

        return null;
    }

    /**
     * Checks a text before it is saved.
     *
     * @return list<string> problems in plain words, empty when the text is good
     */
    public static function problems(string $key, string $channel, string $locale, ?string $subject, string $body): array
    {
        $entry = MessageTemplateCatalog::find($key, $channel);
        if ($entry === null || ! in_array($locale, MessageTemplateCatalog::Locales, true)) {
            return [(string) __('message_templates.err_unknown')];
        }
        $problems = [];
        if (trim($body) === '') {
            $problems[] = (string) __('message_templates.err_empty_body');
        }
        $isSms = $channel === MessageTemplateCatalog::Sms;
        if ($isSms && mb_strlen($body) > self::SmsMaxLength) {
            $problems[] = (string) __('message_templates.err_too_long', ['max' => self::SmsMaxLength]);
        }
        if (! $isSms) {
            if (trim((string) $subject) === '') {
                $problems[] = (string) __('message_templates.err_empty_subject');
            }
            if (mb_strlen((string) $subject) > self::EmailSubjectMaxLength || mb_strlen($body) > self::EmailBodyMaxLength) {
                $problems[] = (string) __('message_templates.err_too_long', ['max' => self::EmailBodyMaxLength]);
            }
        }
        if ($isSms && $locale === SmsText::Locale && trim($body) !== '' && ! SmsText::hasBanglaLetters($body)) {
            $problems[] = (string) __('message_templates.err_not_bangla');
        }
        $used = MessageTemplateCatalog::tokensIn($body.' '.(string) $subject);
        foreach (array_diff($used, $entry['placeholders']) as $unknown) {
            $problems[] = (string) __('message_templates.err_unknown_placeholder', ['name' => '{'.$unknown.'}']);
        }
        // Required placeholders belong in the body (the subject is shorter and often plain).
        $inBody = MessageTemplateCatalog::tokensIn($body);
        foreach (array_diff($entry['required'], $inBody) as $missing) {
            $problems[] = (string) __('message_templates.err_missing_placeholder', ['name' => '{'.$missing.'}']);
        }

        return $problems;
    }

    /**
     * Saves one override and writes the audit line.
     *
     * @throws ValidationException
     */
    public static function save(User $actor, string $key, string $channel, string $locale, ?string $subject, string $body): MessageTemplate
    {
        $problems = self::problems($key, $channel, $locale, $subject, $body);
        if ($problems !== []) {
            throw ValidationException::withMessages(['body' => $problems]);
        }
        $existing = MessageTemplate::query()->where(['key' => $key, 'channel' => $channel, 'locale' => $locale])->first();
        $row = MessageTemplate::query()->updateOrCreate(
            ['key' => $key, 'channel' => $channel, 'locale' => $locale],
            ['subject' => $channel === MessageTemplateCatalog::Email ? trim((string) $subject) : null, 'body' => $body, 'active' => true, 'updated_by' => $actor->id],
        );
        self::forget();
        Audit::record($actor->id, 'message_template.saved', $key, [
            'channel' => $channel, 'locale' => $locale,
            'before' => $existing?->body, 'after' => $body,
            'before_subject' => $existing?->subject, 'after_subject' => $row->subject,
        ]);

        return $row;
    }

    /** Deletes the override so the default text comes back. */
    public static function reset(User $actor, string $key, string $channel, string $locale): void
    {
        $row = MessageTemplate::query()->where(['key' => $key, 'channel' => $channel, 'locale' => $locale])->first();
        if (! $row instanceof MessageTemplate) {
            return;
        }
        $row->delete();
        self::forget();
        Audit::record($actor->id, 'message_template.reset', $key, ['channel' => $channel, 'locale' => $locale, 'before' => $row->body]);
    }

    public static function forget(): void
    {
        Cache::forget(self::CacheKey);
    }

    /**
     * Everything the editing screen needs, in the signed-in person's language.
     *
     * @return array<string, mixed>
     */
    public static function page(): array
    {
        $saved = MessageTemplate::query()->with('editor:id,name')->get()->groupBy(fn (MessageTemplate $row): string => $row->channel.'|'.$row->key);
        $items = [];
        foreach (MessageTemplateCatalog::all() as $entry) {
            $slug = str_replace('.', '_', $entry['key']);
            $titleKey = $entry['channel'] === MessageTemplateCatalog::Email && ! in_array($entry['key'], [MessageTemplateCatalog::ResetLinkKey, MessageTemplateCatalog::CredentialsMailKey], true)
                ? 'event_emails.label_'.$slug
                : 'message_templates.title_'.$slug;
            $locales = $entry['channel'] === MessageTemplateCatalog::Sms ? [SmsText::Locale] : MessageTemplateCatalog::Locales;
            $versions = [];
            foreach ($locales as $locale) {
                $default = MessageTemplateCatalog::defaultSource($entry['key'], $entry['channel'], $locale);
                $row = null;
                foreach ($saved->get($entry['channel'].'|'.$entry['key'], collect()) as $candidate) {
                    if ($candidate->locale === $locale) {
                        $row = $candidate;
                    }
                }
                $versions[$locale] = [
                    'default_subject' => $default['subject'],
                    'default_body' => $default['body'],
                    'subject' => $row === null ? $default['subject'] : $row->subject,
                    'body' => $row === null ? $default['body'] : $row->body,
                    'customised' => $row !== null,
                    'last_changed' => $row === null ? null : ['by' => (string) ($row->editor->name ?? '—'), 'at' => $row->updated_at?->toIso8601String()],
                ];
            }
            $items[] = [
                'key' => $entry['key'],
                'channel' => $entry['channel'],
                'group' => $entry['group'],
                'recipient' => $entry['recipient'],
                'title' => (string) Lang::get($titleKey),
                'when' => (string) Lang::get('message_templates.when_'.$slug),
                'required' => $entry['required'],
                'placeholders' => array_map(fn (string $name): array => [
                    'name' => $name,
                    'label' => (string) Lang::get('message_templates.ph_'.$name),
                    'sample_bn' => MessageTemplateCatalog::sample($name, 'bn'),
                    'sample_en' => MessageTemplateCatalog::sample($name, 'en'),
                ], $entry['placeholders']),
                'locales' => $versions,
            ];
        }

        return [
            'items' => $items,
            'groups' => array_map(fn (string $group): array => ['key' => $group, 'label' => (string) __('message_templates.group_'.$group)], MessageTemplateCatalog::groupOrder()),
        ];
    }
}
