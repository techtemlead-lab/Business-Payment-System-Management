<?php

use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

Artisan::command('dealer:admin {email} {--name=Company Admin}', function () {
    $email = strtolower($this->argument('email'));
    $password = $this->secret('Admin password (12+ characters, mixed case, number and symbol)');
    $v = Validator::make(['email' => $email, 'password' => $password], ['email' => 'required|email|unique:users,email', 'password' => ['required', Password::min(12)->mixedCase()->numbers()->symbols()]]);
    if ($v->fails()) {
        $this->error($v->errors()->first());

        return 1;
    }
    $user = User::create(['name' => $this->option('name'), 'email' => $email, 'password' => $password]);
    $user->forceFill(['role' => 'admin'])->save();
    AuditEvent::record('admin.bootstrapped', ['user_id' => $user->id]);
    $this->info('Admin created. Enroll two-factor authentication at first login.');

    return 0;
})->purpose('Create a company administrator without public registration');
