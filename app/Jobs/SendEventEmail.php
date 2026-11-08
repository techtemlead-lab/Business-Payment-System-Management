<?php

namespace App\Jobs;

use App\Domain\Integrations\SmtpMailConfig;
use App\Domain\Notifications\EmailDeliveryLog;
use App\Domain\Notifications\EmailEventSwitches;
use App\Domain\Notifications\PartnerEventEmail;
use App\Mail\EventNoticeMail;
use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one event email in the background. A mail failure can never reach the action that
 * triggered it: the job tries again a few times on a real queue, then records the failure
 * for staff to see and finishes quietly.
 */
class SendEventEmail implements ShouldQueue
{
    use Queueable;

    private const RetryAfterSeconds = 120;

    public int $tries = 3;

    public int $timeout = 180;

    public function __construct(public int $userId, public string $event, public string $title, public string $body) {}

    public function handle(SmtpMailConfig $smtp, EmailEventSwitches $switches): void
    {
        $user = User::query()->find($this->userId);
        if (! $user instanceof User || ! PartnerEventEmail::isRealAddress($user->email)) {
            return;
        }
        $address = trim((string) $user->email);
        try {
            // A worker runs for hours, so mail settings saved since it started are read again here.
            $smtp->apply();
            Mail::purge();
            if (! PartnerEventEmail::realMailConfigured() || ! $switches->isOn($this->event)) {
                return;
            }
            Mail::to($address)->send(new EventNoticeMail(
                noticeTitle: $this->title,
                noticeBody: $this->body,
                recipientName: (string) $user->name,
                portalUrl: PortalHost::url(PortalHost::forUser($user), '/'),
                language: $user->preferredLocale(),
            ));
        } catch (Throwable $exception) {
            if ($this->willRunAgain()) {
                $this->release(self::RetryAfterSeconds);

                return;
            }
            EmailDeliveryLog::failed($this->event, $address, $exception);
        }
    }

    /** Runs when the worker itself gave up on the job, for example after a timeout. */
    public function failed(?Throwable $exception): void
    {
        $email = (string) User::query()->whereKey($this->userId)->value('email');
        if ($email !== '' && $exception !== null) {
            EmailDeliveryLog::failed($this->event, $email, $exception);
        }
    }

    private function willRunAgain(): bool
    {
        return $this->job !== null && $this->job->getConnectionName() !== 'sync' && $this->attempts() < $this->tries;
    }
}
