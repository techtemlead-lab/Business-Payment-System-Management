<?php

namespace App\Console\Commands;

use App\Domain\Notifications\JoinAnniversaryGreetings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('partners:anniversary-greetings')]
#[Description('Post a short in-app greeting to each active dealer and sub-dealer who joined on this day in an earlier year (no SMS), once a year each')]
class SendJoinAnniversaryGreetings extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(JoinAnniversaryGreetings $greetings): int
    {
        $result = $greetings->sendForToday();
        $this->info("Join anniversary greetings: {$result['sent']} sent, {$result['already_sent']} already sent this year.");

        return self::SUCCESS;
    }
}
