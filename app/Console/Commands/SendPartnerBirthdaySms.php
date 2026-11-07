<?php

namespace App\Console\Commands;

use App\Domain\Notifications\BirthdayGreetings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('partners:birthday-sms')]
#[Description('Send a short birthday SMS to each active dealer and sub-dealer whose birthday is today (Dhaka time), once a year each')]
class SendPartnerBirthdaySms extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BirthdayGreetings $greetings): int
    {
        $result = $greetings->sendForToday();
        if (($result['sms_ready'] ?? true) === false) {
            $this->info('SMS is not set up (or trial mode is on). No birthday SMS sent.');

            return self::SUCCESS;
        }
        if (! $result['enabled']) {
            $this->info('Birthday SMS is switched off in dashboard management. No SMS sent.');

            return self::SUCCESS;
        }
        $this->line(json_encode($result, JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
