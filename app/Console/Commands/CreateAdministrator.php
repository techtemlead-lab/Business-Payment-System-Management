<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;

#[Signature('madina:admin {email : Administrator email} {--name=Company Administrator} {--invite : Email a one-use password-setting link instead of prompting for a password}')]
#[Description('Create the first administrator with a private password or invitation; no shared default password.')]
class CreateAdministrator extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (User::where('role', 'super_admin')->exists()) {
            $this->error('An administrator already exists. Use the staff account-management workflow.');

            return self::FAILURE;
        }
        if (! $this->option('invite') && ! $this->input->isInteractive()) {
            $this->error('Use --invite for non-interactive setup. Passwords must not be passed as command-line arguments.');

            return self::FAILURE;
        }
        if ($this->option('invite') && config('mail.default') === 'log') {
            $this->error('Configure a private mail transport first. Invitations must not be written to logs.');

            return self::FAILURE;
        }
        $password = $this->option('invite') ? Str::random(48) : $this->secret('Choose a strong administrator password');
        $data = ['name' => $this->option('name'), 'email' => $this->argument('email'), 'password' => $password];
        $validator = Validator::make($data, ['name' => 'required|string|max:150', 'email' => 'required|email|max:254|unique:users',
            'password' => ['required', PasswordRule::min(12)->mixedCase()->numbers()]]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }
        $user = DB::transaction(function () use ($data): User {
            $user = (new User)->forceFill(['name' => $data['name'], 'email' => $data['email'], 'role' => 'super_admin', 'status' => 'active', 'password' => $data['password']]);
            $user->save();
            DB::table('wallets')->insert(['user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
            Audit::record(null, 'administrator.bootstrapped', (string) $user->id, ['source' => 'authorized_cli']);

            return $user;
        });
        if ($this->option('invite')) {
            Password::sendResetLink(['email' => $user->email]);
        }
        $this->info('Administrator created. No password or invitation token was printed.');

        return self::SUCCESS;
    }
}
