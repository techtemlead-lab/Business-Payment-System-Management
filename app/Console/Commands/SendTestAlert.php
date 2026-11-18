<?php

namespace App\Console\Commands;

use App\Logging\CreateAlertLogger;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('ops:alert-test')]
#[Description('Send one test alert to the configured Telegram chat and alert email addresses')]
class SendTestAlert extends Command
{
    public function handle(): int
    {
        if (! CreateAlertLogger::configured()) {
            $this->error('No alert path is set. Fill MADINA_ALERT_TELEGRAM_BOT_TOKEN and MADINA_ALERT_TELEGRAM_CHAT_ID, or MADINA_ALERT_EMAILS, then rebuild the config cache.');

            return self::FAILURE;
        }
        // The time makes each test message new, so the repeat window does not hold it back.
        Log::channel('alerts')->critical('Test alert from the server ('.now('Asia/Dhaka')->format('d M Y H:i:s').'). No action needed.');
        $this->info('Test alert sent. If it does not arrive within a minute, check the bot token, chat id and the container log.');

        return self::SUCCESS;
    }
}
