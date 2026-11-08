<?php

namespace App\Domain\Integrations;

use App\Domain\Notifications\SmsText;
use App\Support\RedactedError;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class SmsGatewayClient
{
    public function __construct(private IntegrationSettings $settings) {}

    /**
     * Sends through the first enabled provider that accepts the text. A non-OTP text
     * with no Bangla letters is refused before any provider is called, so a bad text
     * can never put the SMS account at risk: `refused` tells the caller it happened.
     *
     * @return array{sent: bool, refused: bool, provider: ?string, message: string}
     */
    public function send(string $phone, string $message, bool $isOtp = false): array
    {
        if (! $isOtp && ! SmsText::hasBanglaLetters($message)) {
            $this->log($phone, $message, null, false, true);
            Log::warning('sms.refused_not_bangla', ['phone' => $this->maskedPhone($phone), 'length' => mb_strlen($message)]);

            return ['sent' => false, 'refused' => true, 'provider' => null, 'message' => SmsText::make('portal.sms_refused_not_bangla')];
        }

        foreach (array_keys(IntegrationCatalog::groups()['sms']['providers']) as $provider) {
            $config = $this->settings->provider('sms', $provider);
            if (! ($config['enabled'] ?? false)) {
                continue;
            }

            try {
                $ok = match ($provider) {
                    'mim_sms' => $this->sendMimSms($config, $phone, $message),
                    'alpha_sms' => $this->sendAlphaSms($config, $phone, $message),
                    'greenweb' => $this->sendGreenweb($config, $phone, $message),
                    'ssl_wireless' => $this->sendSslWireless($config, $phone, $message),
                    'bulk_sms_bd' => $this->sendBulkSmsBd($config, $phone, $message),
                    default => $this->sendCustom($config, $phone, $message),
                };
                if ($ok) {
                    $this->log($phone, $message, $provider, true);

                    return ['sent' => true, 'refused' => false, 'provider' => $provider, 'message' => __('portal.si_sms_accepted', ['provider' => $provider])];
                }
            } catch (\Throwable $exception) {
                Log::warning('sms.gateway_failed', ['provider' => $provider, 'error' => RedactedError::message($exception)]);
            }
        }
        $this->log($phone, $message, null, false);

        return ['sent' => false, 'refused' => false, 'provider' => null, 'message' => __('portal.si_sms_none_accepted')];
    }

    /**
     * Keep a short record of the attempt (masked number, no message text). A failure to log never stops an SMS.
     */
    private function log(string $phone, string $message, ?string $provider, bool $sent, bool $refused = false): void
    {
        try {
            DB::table('sms_logs')->insert([
                'phone_masked' => $this->maskedPhone($phone),
                'provider' => $provider,
                'sent' => $sent,
                'refused' => $refused,
                'length' => mb_strlen($message),
                'created_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Log::warning('sms.log_failed', ['error' => RedactedError::message($exception)]);
        }
    }

    private function maskedPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return strlen($digits) > 4 ? '••••'.substr($digits, -4) : '••••';
    }

    public function anyEnabled(): bool
    {
        foreach (array_keys(IntegrationCatalog::groups()['sms']['providers']) as $provider) {
            if ($this->settings->isEnabled('sms', $provider)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when at least one SMS provider is enabled with send credentials.
     * Logging/array drivers alone do not count — login OTP must not trap users.
     */
    public function isConfigured(): bool
    {
        foreach (array_keys(IntegrationCatalog::groups()['sms']['providers'] ?? []) as $provider) {
            $config = $this->settings->provider('sms', $provider);
            if (! ($config['enabled'] ?? false)) {
                continue;
            }
            if (filled($config['base_url'] ?? null) && filled($config['api_key'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{ok: bool, provider: ?string, balance: ?string, message: string}
     */
    public function balance(?string $provider = null): array
    {
        $providers = $provider
            ? [$provider]
            : array_keys(IntegrationCatalog::groups()['sms']['providers']);

        foreach ($providers as $key) {
            $config = $this->settings->provider('sms', $key);
            if (! ($config['enabled'] ?? false)) {
                continue;
            }
            try {
                $balance = match ($key) {
                    'mim_sms' => $this->balanceMimSms($config),
                    'alpha_sms' => $this->balanceAlphaSms($config),
                    'greenweb' => $this->balanceGreenweb($config),
                    'ssl_wireless' => $this->balanceSslWireless($config),
                    'bulk_sms_bd' => $this->balanceBulkSmsBd($config),
                    default => $this->balanceCustom($config),
                };
                if ($balance !== null) {
                    return [
                        'ok' => true,
                        'provider' => $key,
                        'balance' => $balance,
                        'message' => __('portal.si_sms_balance_value', ['balance' => $balance]),
                    ];
                }
            } catch (\Throwable $exception) {
                Log::warning('sms.balance_failed', ['provider' => $key, 'error' => RedactedError::message($exception)]);
            }
        }

        return [
            'ok' => false,
            'provider' => $provider,
            'balance' => null,
            'message' => __('portal.si_sms_balance_unavailable'),
        ];
    }

    /**
     * MIM SMS balance (API v2.1). The amount is in data[0].balance; the GET form of the
     * call returns it in responseResult instead, so that is read too.
     *
     * @param  array<string, mixed>  $config
     */
    private function balanceMimSms(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        $apiKey = (string) ($config['api_key'] ?? '');
        $userName = (string) ($config['username'] ?? '');
        if ($url === '' || $apiKey === '' || $userName === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->asJson()->post($url, [
            'userName' => $userName,
            'apiKey' => $apiKey,
        ]);
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || (string) ($json['statusCode'] ?? '') !== '200'
            || ! in_array(strtolower((string) ($json['status'] ?? '')), ['success', 'ok'], true)) {
            return null;
        }
        $value = $json['data'][0]['balance'] ?? $json['responseResult'] ?? null;

        return $value === null ? null : mb_strimwidth((string) $value, 0, 60);
    }

    /**
     * Alpha SMS documents the balance call only with the key in the query string.
     * Any failure is logged through RedactedError, which removes query strings.
     *
     * @param  array<string, mixed>  $config
     */
    private function balanceAlphaSms(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        $apiKey = (string) ($config['api_key'] ?? '');
        if ($url === '' || $apiKey === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->get($url, ['api_key' => $apiKey]);
        $json = $response->json();
        if (! $response->successful() || ! is_array($json) || ! $this->alphaAccepted($json)) {
            return null;
        }
        $value = $json['data']['balance'] ?? null;

        return $value === null ? null : mb_strimwidth((string) $value, 0, 60);
    }

    /**
     * Greenweb documents the balance call only with the token in the query string.
     * Any failure is logged through RedactedError, which removes query strings.
     *
     * @param  array<string, mixed>  $config
     */
    private function balanceGreenweb(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->get($url, [
            'token' => $config['api_key'],
            'balance' => '',
        ]);
        $reply = trim($response->body());
        if (! $response->successful() || $reply === '' || preg_match('/^error\b/i', $reply) === 1) {
            return null;
        }

        return mb_strimwidth($reply, 0, 60);
    }

    /** @param  array<string, mixed>  $config */
    private function balanceSslWireless(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->asJson()->post($url, [
            'api_token' => $config['api_key'],
            'sid' => $config['sender_id'] ?: $config['api_secret'],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $value = $json['balance'] ?? $json['data']['balance'] ?? $json['Balance'] ?? null;

        return $value === null ? null : (string) $value;
    }

    /** @param  array<string, mixed>  $config */
    private function balanceBulkSmsBd(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? 'http://bulksmsbd.net/api/getBalanceApi'));
        $response = Http::connectTimeout(3)->timeout(12)->get($url, [
            'api_key' => $config['api_key'],
        ]);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        if (is_array($json)) {
            $value = $json['balance'] ?? $json['data']['balance'] ?? $json['Balance'] ?? null;
            if ($value !== null) {
                return (string) $value;
            }
        }
        $body = trim($response->body());

        return $body !== '' ? $body : null;
    }

    /** @param  array<string, mixed>  $config */
    private function balanceCustom(array $config): ?string
    {
        $url = trim((string) ($config['balance_url'] ?? ''));
        if ($url === '') {
            return null;
        }
        $response = Http::connectTimeout(3)->timeout(12)->acceptJson()->withToken((string) $config['api_key'])->get($url);
        if (! $response->successful()) {
            return null;
        }
        $json = $response->json();
        $value = is_array($json) ? ($json['balance'] ?? $json['data']['balance'] ?? $json['remaining'] ?? null) : null;

        return $value === null ? null : (string) $value;
    }

    /**
     * Greenweb reports the result in the reply text, so only a reply that starts
     * with "Ok:" and has no "Error:" line counts as sent. The token goes in the
     * form body, never in the URL. See https://bdbulksms.com/bd-bulk-sms-api.php.
     *
     * @param  array<string, mixed>  $config
     */
    private function sendGreenweb(array $config, string $phone, string $message): bool
    {
        $base = rtrim((string) $config['base_url'], '/');
        $token = (string) $config['api_key'];
        if ($base === '' || $token === '') {
            return false;
        }
        $response = Http::connectTimeout(3)->timeout(15)->asForm()->post($base, [
            'token' => $token,
            'to' => preg_replace('/^88(?=01)/', '', $phone),
            'message' => $message,
        ]);
        $reply = trim($response->body());
        if ($response->successful() && preg_match('/^ok\s*:/i', $reply) === 1 && preg_match('/^\s*error\s*:/im', $reply) !== 1) {
            return true;
        }
        Log::warning('sms.greenweb_rejected', [
            'status' => $response->status(),
            'reply' => RedactedError::text(mb_strimwidth(str_replace([$token, $message], '[hidden]', $reply), 0, 120)),
        ]);

        return false;
    }

    /**
     * MIM SMS transactional send (API v2.1): one number per request, JSON body, and only
     * statusCode 200 with status Success counts as accepted. MIM repeats the number in
     * its reply, so the reply is logged with the key, login, text and number hidden.
     *
     * @param  array<string, mixed>  $config
     */
    private function sendMimSms(array $config, string $phone, string $message): bool
    {
        $base = rtrim((string) $config['base_url'], '/');
        $apiKey = (string) $config['api_key'];
        $userName = (string) ($config['username'] ?? '');
        $senderName = (string) ($config['sender_id'] ?? '');
        if ($base === '' || $apiKey === '' || $userName === '' || $senderName === '') {
            return false;
        }
        $number = $this->internationalPhone($phone);
        $response = Http::connectTimeout(3)->timeout(15)->acceptJson()->asJson()->post($base, [
            'apiKey' => $apiKey,
            'userName' => $userName,
            'campaignName' => 'null',
            'senderName' => $senderName,
            'transactionType' => 'T',
            'mobileNumber' => $number,
            'message' => $message,
        ]);
        $json = $response->json();
        if ($response->successful() && is_array($json) && (string) ($json['statusCode'] ?? '') === '200'
            && strcasecmp((string) ($json['status'] ?? ''), 'Success') === 0) {
            return true;
        }
        Log::warning('sms.mim_rejected', [
            'status' => $response->status(),
            'reply' => $this->loggableReply($response->body(), [$apiKey, $userName, $message, $number, $phone]),
        ]);

        return false;
    }

    /**
     * Alpha SMS (sms.net.bd): success is "error": 0 in the JSON reply. The key goes in
     * the form body, never in the URL. See https://sms.bd/api.
     *
     * @param  array<string, mixed>  $config
     */
    private function sendAlphaSms(array $config, string $phone, string $message): bool
    {
        $base = rtrim((string) $config['base_url'], '/');
        $apiKey = (string) $config['api_key'];
        if ($base === '' || $apiKey === '') {
            return false;
        }
        $number = $this->internationalPhone($phone);
        $payload = ['api_key' => $apiKey, 'msg' => $message, 'to' => $number];
        if (filled($config['sender_id'] ?? null)) {
            $payload['sender_id'] = (string) $config['sender_id'];
        }
        $response = Http::connectTimeout(3)->timeout(15)->acceptJson()->asForm()->post($base, $payload);
        $json = $response->json();
        if ($response->successful() && is_array($json) && $this->alphaAccepted($json)) {
            return true;
        }
        Log::warning('sms.alpha_rejected', [
            'status' => $response->status(),
            'reply' => $this->loggableReply($response->body(), [$apiKey, $message, $number, $phone]),
        ]);

        return false;
    }

    /** @param  array<mixed>  $json */
    private function alphaAccepted(array $json): bool
    {
        return is_numeric($json['error'] ?? null) && (int) $json['error'] === 0;
    }

    /** MIM and Alpha both take the number as 8801XXXXXXXXX. */
    private function internationalPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        return str_starts_with($digits, '01') ? '88'.$digits : $digits;
    }

    /**
     * A provider's refusal, cut short and with everything secret or personal removed.
     *
     * @param  list<string>  $hidden
     */
    private function loggableReply(string $reply, array $hidden): string
    {
        return RedactedError::text(mb_strimwidth(str_replace($hidden, '[hidden]', trim($reply)), 0, 120));
    }

    /** @param  array<string, mixed>  $config */
    private function sendSslWireless(array $config, string $phone, string $message): bool
    {
        $response = Http::timeout(15)->acceptJson()->asJson()->post(rtrim((string) $config['base_url'], '/'), [
            'api_token' => $config['api_key'],
            'sid' => $config['sender_id'] ?: $config['api_secret'],
            'msisdn' => $phone,
            'sms' => $message,
            'csms_id' => uniqid('madina_', true),
        ]);

        return $response->successful();
    }

    /** @param  array<string, mixed>  $config */
    private function sendBulkSmsBd(array $config, string $phone, string $message): bool
    {
        $response = Http::timeout(15)->get(rtrim((string) $config['base_url'], '/'), [
            'api_key' => $config['api_key'],
            'type' => 'text',
            'number' => $phone,
            'senderid' => $config['sender_id'],
            'message' => $message,
        ]);

        return $response->successful();
    }

    /** @param  array<string, mixed>  $config */
    private function sendCustom(array $config, string $phone, string $message): bool
    {
        $base = rtrim((string) $config['base_url'], '/');
        if ($base === '') {
            return false;
        }
        $response = Http::timeout(15)->acceptJson()->withToken((string) $config['api_key'])->post($base, [
            'to' => $phone,
            'message' => $message,
            'sender_id' => $config['sender_id'] ?? null,
        ]);

        return $response->successful();
    }
}
