<?php

namespace App\Logging;

use Monolog\Logger;

/**
 * Builds the "alerts" log channel from config('madina.alerts'). The channel joins the default log
 * stack only when a Telegram chat or an email address is configured (see config/logging.php).
 */
final class CreateAlertLogger
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __invoke(array $config): Logger
    {
        /** @var array{telegram_bot_token: ?string, telegram_chat_id: ?string, emails: ?string, level: ?string, repeat_minutes: int|string|null} $alerts */
        $alerts = config('madina.alerts');
        $emails = array_values(array_filter(array_map('trim', explode(',', (string) $alerts['emails'])), fn (string $email): bool => filter_var($email, FILTER_VALIDATE_EMAIL) !== false));

        return new Logger('alerts', [new AlertHandler(
            (string) $alerts['telegram_bot_token'],
            (string) $alerts['telegram_chat_id'],
            $emails,
            (int) $alerts['repeat_minutes'],
            Logger::toMonologLevel((string) ($alerts['level'] ?: 'critical')),
        )]);
    }

    /** True when at least one way of reaching a person is filled in. */
    public static function configured(): bool
    {
        $alerts = config('madina.alerts');

        return (filled($alerts['telegram_bot_token'] ?? null) && filled($alerts['telegram_chat_id'] ?? null))
            || filled($alerts['emails'] ?? null);
    }
}
