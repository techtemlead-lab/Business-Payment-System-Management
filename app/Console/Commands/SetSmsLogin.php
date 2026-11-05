<?php

namespace App\Console\Commands;

use App\Domain\Security\SecuritySettings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('madina:sms-login {state : on or off}')]
#[Description('Turn the SMS sign-in code on or off from the server, for when SMS is down and nobody can sign in to use the owner switch.')]
class SetSmsLogin extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(SecuritySettings $security): int
    {
        $state = strtolower((string) $this->argument('state'));
        if (! in_array($state, ['on', 'off'], true)) {
            $this->error('Use "on" or "off".');

            return self::FAILURE;
        }
        $security->savePartnerLoginOtpFromServer($state === 'on');
        $this->info($state === 'on'
            ? 'The SMS sign-in code is ON. Make sure SMS is working before anyone signs out.'
            : 'The SMS sign-in code is OFF. Turn it back on from the owner Security page once SMS works.');

        return self::SUCCESS;
    }
}
