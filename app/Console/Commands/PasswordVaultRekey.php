<?php

namespace App\Console\Commands;

use App\Support\Audit;
use App\Support\PasswordVault;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;

#[Signature('madina:password-vault-rekey {--generate-key : Only print a new key to put in PASSWORD_VAULT_KEY}')]
#[Description('Move the readable copies of dealer and sub-dealer passwords from APP_KEY to the separate password-vault key.')]
class PasswordVaultRekey extends Command
{
    public function handle(PasswordVault $vault): int
    {
        if ($this->option('generate-key')) {
            $this->line(PasswordVault::generateKey());

            return self::SUCCESS;
        }
        if (! $vault->configured()) {
            $this->error('PASSWORD_VAULT_KEY (or PASSWORD_VAULT_KEY_FILE) is not set. Run with --generate-key, store the key outside the backups, then run this again.');

            return self::FAILURE;
        }
        $moved = 0;
        $already = 0;
        $unreadable = 0;
        DB::table('users')->whereNotNull('admin_password_cipher')->orderBy('id')->select(['id', 'admin_password_cipher'])
            ->chunkById(200, function ($rows) use ($vault, &$moved, &$already, &$unreadable): void {
                foreach ($rows as $row) {
                    $payload = (string) $row->admin_password_cipher;
                    if ($vault->usesVaultKey($payload)) {
                        $already++;

                        continue;
                    }
                    try {
                        $plain = $vault->decrypt($payload);
                    } catch (DecryptException) {
                        // Neither key opens it (APP_KEY was changed since): the owner resets that password.
                        $unreadable++;

                        continue;
                    }
                    DB::table('users')->where('id', $row->id)->update(['admin_password_cipher' => $vault->encrypt($plain)]);
                    $moved++;
                }
            });
        Audit::record(null, 'security.password_vault_rekeyed', 'users', ['moved' => $moved, 'already' => $already, 'unreadable' => $unreadable]);
        $this->info("Moved to the vault key: {$moved}. Already on it: {$already}. Could not be opened: {$unreadable}.");

        return self::SUCCESS;
    }
}
