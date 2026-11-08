<?php

namespace App\Domain\Integrations;

/**
 * Applies admin-configured SMTP settings to the runtime mailer when enabled.
 */
final class SmtpMailConfig
{
    public function __construct(private IntegrationSettings $settings) {}

    public function enabled(): bool
    {
        return $this->settings->isEnabled('mail', 'smtp');
    }

    public function apply(): void
    {
        $config = $this->settings->provider('mail', 'smtp');
        if (! ($config['enabled'] ?? false)) {
            return;
        }

        $host = trim((string) ($config['host'] ?? ''));
        if ($host === '') {
            return;
        }

        $port = (int) ($config['port'] ?? 587);
        $encryption = strtolower(trim((string) ($config['encryption'] ?? 'tls')));
        if (! in_array($encryption, ['tls', 'ssl', ''], true)) {
            $encryption = 'tls';
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $host,
            'mail.mailers.smtp.port' => $port > 0 ? $port : 587,
            'mail.mailers.smtp.encryption' => $encryption === '' ? null : $encryption,
            // Laravel chooses TLS from the scheme alone, and the server's MAIL_SCHEME may say smtps. Port 587 (Amazon SES)
            // has to stay plain smtp so the connection can upgrade with STARTTLS; smtps is only for SSL or port 465.
            'mail.mailers.smtp.scheme' => ($encryption === 'ssl' || $port === 465) ? 'smtps' : 'smtp',
            'mail.mailers.smtp.username' => (string) ($config['username'] ?? ''),
            'mail.mailers.smtp.password' => (string) ($config['password'] ?? ''),
            'mail.from.address' => (string) ($config['from_address'] ?? config('mail.from.address')),
            'mail.from.name' => (string) ($config['from_name'] ?? config('mail.from.name')),
        ]);
    }
}
