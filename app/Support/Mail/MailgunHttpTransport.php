<?php

namespace App\Support\Mail;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\MessageConverter;

/**
 * Sends mail through Mailgun's HTTP API using Laravel's own HTTP client, so no extra
 * composer package is needed. The API key is only ever sent as the basic-auth password and
 * never appears in an exception message, a log line or the URL.
 */
final class MailgunHttpTransport extends AbstractTransport
{
    private const DefaultEndpoint = 'api.mailgun.net';

    public function __construct(
        private readonly string $domain,
        private readonly string $secret,
        private readonly string $endpoint = self::DefaultEndpoint,
        private readonly int $timeout = 15,
    ) {
        parent::__construct();
    }

    /**
     * True when the sending domain and the key are both present (used by the readiness checks).
     *
     * @param  array<string, mixed>  $config  one entry of config('mail.mailers')
     */
    public static function isConfigured(array $config): bool
    {
        return self::validDomain((string) ($config['domain'] ?? ''))
            && trim((string) ($config['secret'] ?? '')) !== '';
    }

    public function __toString(): string
    {
        return 'mailgun-http://'.$this->endpoint;
    }

    protected function doSend(SentMessage $message): void
    {
        if (! self::validDomain($this->domain) || trim($this->secret) === '') {
            throw new TransportException('Mailgun is not set up: the sending domain or the API key is missing (MAILGUN_DOMAIN, MAILGUN_SECRET).');
        }
        $endpoint = trim($this->endpoint) === '' ? self::DefaultEndpoint : trim($this->endpoint);
        if (! preg_match('/^[A-Za-z0-9.-]+$/', $endpoint)) {
            throw new TransportException('Mailgun endpoint is not a valid host name (MAILGUN_ENDPOINT).');
        }

        $original = $message->getOriginalMessage();
        if (! $original instanceof Message) {
            throw new TransportException('Mailgun can only send a complete email message.');
        }
        $email = MessageConverter::toEmail($original);
        $url = 'https://'.$endpoint.'/v3/'.$this->domain.'/messages';

        try {
            $response = Http::withBasicAuth('api', $this->secret)
                ->timeout($this->timeout)
                ->asMultipart()
                ->post($url, $this->parts($email, $message));
        } catch (ConnectionException) {
            throw new TransportException('Could not reach Mailgun (connection failed or timed out). The message was not sent.');
        }

        if (! $response->successful()) {
            throw new TransportException(sprintf(
                'Mailgun refused the message (HTTP %d): %s',
                $response->status(),
                str_replace($this->secret, '***', $this->reason($response->json('message'), $response->body())),
            ));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parts(Email $email, SentMessage $message): array
    {
        $envelope = $message->getEnvelope();
        $to = array_map(fn (Address $a) => $a->toString(), $email->getTo());
        $cc = array_map(fn (Address $a) => $a->toString(), $email->getCc());
        $bcc = array_map(fn (Address $a) => $a->toString(), $email->getBcc());

        // The envelope is the final list of recipients; anything in it that is not in the
        // visible headers is a blind copy.
        $known = array_map('strtolower', array_map(fn (Address $a) => $a->getAddress(), [...$email->getTo(), ...$email->getCc(), ...$email->getBcc()]));
        foreach ($envelope->getRecipients() as $recipient) {
            if (! in_array(strtolower($recipient->getAddress()), $known, true)) {
                $bcc[] = $recipient->toString();
            }
        }

        $parts = [['name' => 'from', 'contents' => $envelope->getSender()->toString()]];
        foreach ($to as $value) {
            $parts[] = ['name' => 'to', 'contents' => $value];
        }
        foreach ($cc as $value) {
            $parts[] = ['name' => 'cc', 'contents' => $value];
        }
        foreach ($bcc as $value) {
            $parts[] = ['name' => 'bcc', 'contents' => $value];
        }
        $parts[] = ['name' => 'subject', 'contents' => (string) $email->getSubject()];
        if ($email->getTextBody() !== null && $email->getTextBody() !== '') {
            $parts[] = ['name' => 'text', 'contents' => (string) $email->getTextBody()];
        }
        if ($email->getHtmlBody() !== null && $email->getHtmlBody() !== '') {
            $parts[] = ['name' => 'html', 'contents' => (string) $email->getHtmlBody()];
        }
        foreach ($email->getReplyTo() as $replyTo) {
            $parts[] = ['name' => 'h:Reply-To', 'contents' => $replyTo->toString()];
        }
        $headers = $email->getHeaders();
        if ($headers->has('Message-ID')) {
            $parts[] = ['name' => 'h:Message-Id', 'contents' => '<'.$headers->get('Message-ID')->getBodyAsString().'>'];
        }

        foreach ($email->getAttachments() as $attachment) {
            $disposition = $attachment->getPreparedHeaders()->getHeaderBody('Content-Disposition');
            $parts[] = [
                'name' => $disposition === 'inline' ? 'inline' : 'attachment',
                'contents' => $attachment->getBody(),
                'filename' => $attachment->getPreparedHeaders()->getHeaderParameter('Content-Disposition', 'filename') ?: 'attachment',
                'headers' => ['Content-Type' => $attachment->getMediaType().'/'.$attachment->getMediaSubtype()],
            ];
        }

        return $parts;
    }

    /** Mailgun's own sentence when it sent one (for example "not an authorized recipient"), else a short slice of the body. */
    private function reason(mixed $apiMessage, string $body): string
    {
        $text = is_string($apiMessage) && $apiMessage !== '' ? $apiMessage : $body;
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_substr($text === '' ? 'no reason given' : $text, 0, 300);
    }

    private static function validDomain(string $domain): bool
    {
        return $domain !== '' && preg_match('/^[A-Za-z0-9.-]+$/', $domain) === 1;
    }
}
