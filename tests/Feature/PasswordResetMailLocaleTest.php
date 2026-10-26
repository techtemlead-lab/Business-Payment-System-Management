<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetMailLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_reset_email_uses_partner_bangla_locale(): void
    {
        Notification::fake();

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
        ]);

        $this->onPortal('dealer')
            ->post('/forgot-password', ['email' => $dealer->email])
            ->assertSessionHas('success');

        Notification::assertSentTo($dealer, ResetPassword::class, function (ResetPassword $notification) use ($dealer): bool {
            $previous = app()->getLocale();
            app()->setLocale($dealer->preferredLocale());
            $mail = $notification->toMail($dealer);
            app()->setLocale($previous);

            return $mail->subject === 'পাসওয়ার্ড রিসেট করুন'
                && str_starts_with((string) $mail->actionUrl, PortalHost::url('dealer', '/reset-password/'));
        });
    }

    public function test_password_reset_email_defaults_to_english(): void
    {
        Notification::fake();

        $staff = User::factory()->create([
            'role' => 'finance',
            'status' => 'active',
            'locale' => 'en',
        ]);

        $this->post('/company/forgot-password', ['email' => $staff->email])->assertSessionHas('success');

        Notification::assertSentTo($staff, ResetPassword::class, function (ResetPassword $notification) use ($staff): bool {
            $previous = app()->getLocale();
            app()->setLocale($staff->preferredLocale());
            $mail = $notification->toMail($staff);
            app()->setLocale($previous);

            return $mail->subject === 'Reset your password'
                && str_starts_with((string) $mail->actionUrl, PortalHost::url('manage', '/reset-password/'));
        });
    }
}
