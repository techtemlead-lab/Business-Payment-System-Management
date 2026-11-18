<?php

namespace App\Logging;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

/**
 * Sends serious log lines (critical and above by default) to a person: a Telegram chat and/or an
 * email address. A failed month close, backup, payout run or background job is logged as critical,
 * so it now reaches someone instead of a log file nobody reads.
 *
 * The same problem is sent once per repeat window, so an hourly failure does not flood the phone.
 * Sending never throws: a broken alert path must not break the request or job that logged.
 */
final class AlertHandler extends AbstractProcessingHandler
{
    private const MaxContextLength = 600;

    /**
     * @param  list<string>  $emails
     */
    public function __construct(
        private readonly string $telegramBotToken,
        private readonly string $telegramChatId,
        private readonly array $emails,
        private readonly int $repeatMinutes,
        int|string|Level $level = Level::Critical,
    ) {
        parent::__construct($level, true);
    }

    protected function write(LogRecord $record): void
    {
        try {
            if (! $this->firstInWindow($record)) {
                return;
            }
            $text = $this->text($record);
            if ($this->telegramBotToken !== '' && $this->telegramChatId !== '') {
                Http::timeout(5)->connectTimeout(3)->asForm()
                    ->post('https://api.telegram.org/bot'.$this->telegramBotToken.'/sendMessage', [
                        'chat_id' => $this->telegramChatId,
                        'text' => $text,
                        'disable_web_page_preview' => 'true',
                    ])->throw();
            }
            if ($this->emails !== []) {
                Mail::raw($text, function ($message) use ($record): void {
                    $message->to($this->emails)->subject('['.config('app.name').'] '.$record->level->getName().': '.Str::limit($record->message, 80));
                });
            }
        } catch (Throwable $error) {
            // Logging the failure through Laravel would come back here; write it where the operator's log tools see it.
            error_log('Alert could not be sent: '.$error::class.' '.Str::limit($error->getMessage(), 200));
        }
    }

    /** The cache may live in the database; when the database itself is down, the alert is sent anyway. */
    private function firstInWindow(LogRecord $record): bool
    {
        try {
            return Cache::add('operations:alert-sent:'.sha1($record->level->name.'|'.$record->message), true, now()->addMinutes(max(1, $this->repeatMinutes)));
        } catch (Throwable) {
            return true;
        }
    }

    private function text(LogRecord $record): string
    {
        $lines = [
            config('app.name').' ('.config('app.env').')',
            $record->level->getName().': '.$record->message,
            'Time: '.$record->datetime->setTimezone(new \DateTimeZone('Asia/Dhaka'))->format('d M Y H:i').' Dhaka',
        ];
        $context = $record->context;
        if (($context['exception'] ?? null) instanceof Throwable) {
            $exception = $context['exception'];
            unset($context['exception']);
            $lines[] = 'Error: '.$exception::class.': '.Str::limit($exception->getMessage(), 300);
        }
        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            $lines[] = 'Details: '.Str::limit((string) $encoded, self::MaxContextLength);
        }
        $lines[] = 'Next step: see docs/RUNBOOK.md, section "Alerts".';

        return implode("\n", $lines);
    }
}
