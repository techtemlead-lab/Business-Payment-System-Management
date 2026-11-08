<?php

namespace Tests\Feature;

use App\Domain\Integrations\IntegrationCatalog;
use App\Domain\Integrations\SmsGatewayClient;
use App\Domain\Integrations\SmtpMailConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;
use Tests\TestCase;

/**
 * MIM SMS and Alpha SMS are never called for real: every request goes to Http::fake().
 * All keys and logins here are made up for the tests.
 */
class NewSmsGatewaysTest extends TestCase
{
    use RefreshDatabase;

    private const MimUrl = 'https://api.mimsms.com/api/V2/SMS';

    private const MimBalanceUrl = 'https://api.mimsms.com/api/V2/BalanceCheck';

    private const AlphaUrl = 'https://api.sms.net.bd/sendsms';

    private const AlphaBalanceUrl = 'https://api.sms.net.bd/user/balance/';

    private const GreenwebUrl = 'https://api.bdbulksms.net/api.php';

    private const MimKey = 'mim-test-key-not-real';

    private const MimLogin = 'panel-login@madina-tests.com';

    private const AlphaKey = 'alpha-test-key-not-real';

    private const BanglaText = 'মদিনা: আপনার অর্ডার জমা হয়েছে।';

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        Event::listen(MessageLogged::class, function (MessageLogged $log): void {
            $this->logged[] = $log->message.' '.json_encode($log->context, JSON_UNESCAPED_UNICODE);
        });
    }

    /** @param  array<string, mixed>  $fields */
    private function saveProvider(string $group, string $provider, array $fields): TestResponse
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        return $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => $group,
            'provider' => $provider,
            'version' => (int) (DB::table('business_settings')->where('key', 'service_integrations')->value('version') ?? 0),
            'enabled' => true,
            'reason' => 'The owner chose this service.',
            ...$fields,
        ]);
    }

    private function enableMim(): void
    {
        $this->saveProvider('sms', 'mim_sms', [
            'base_url' => self::MimUrl,
            'balance_url' => self::MimBalanceUrl,
            'api_key' => self::MimKey,
            'username' => self::MimLogin,
            'sender_id' => 'MadinaNHC',
        ])->assertSessionHasNoErrors();
    }

    private function enableAlpha(string $senderId = ''): void
    {
        $this->saveProvider('sms', 'alpha_sms', [
            'base_url' => self::AlphaUrl,
            'balance_url' => self::AlphaBalanceUrl,
            'api_key' => self::AlphaKey,
            'sender_id' => $senderId,
        ])->assertSessionHasNoErrors();
    }

    private function enableGreenweb(): void
    {
        $this->saveProvider('sms', 'greenweb', [
            'base_url' => self::GreenwebUrl,
            'balance_url' => 'https://api.bdbulksms.net/g_api.php',
            'api_key' => 'greenweb-test-token',
        ])->assertSessionHasNoErrors();
    }

    /** @return array<string, mixed> */
    private function mimAccepted(): array
    {
        return [
            'statusCode' => '200',
            'status' => 'Success',
            'trxnId' => 'TEST1',
            'responseResult' => 'SMS Send Successfully',
            'numberQty' => '1',
            'success_Data' => [['mobNumber' => '8801720000088', 'trackingId' => 'TEST1', 'sms_Count' => 1, 'lang_Type' => 'Unicode']],
            'error_Data' => [['res_Code' => '200', 'error' => '', 'failedNumbers' => '', 'errorParm' => '']],
        ];
    }

    public function test_mim_is_first_and_every_provider_starts_switched_off(): void
    {
        $providers = IntegrationCatalog::groups()['sms']['providers'];

        $this->assertSame(['mim_sms', 'greenweb', 'ssl_wireless', 'bulk_sms_bd', 'custom', 'alpha_sms'], array_keys($providers));
        foreach ($providers as $provider) {
            $this->assertFalse($provider['defaults']['enabled']);
        }
    }

    public function test_mim_send_uses_the_documented_request_and_counts_as_sent(): void
    {
        Http::fake([self::MimUrl => Http::response($this->mimAccepted())]);
        $this->enableMim();

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertTrue($result['sent']);
        $this->assertSame('mim_sms', $result['provider']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::MimUrl
            && $request->method() === 'POST'
            && $request->isJson()
            && $request['apiKey'] === self::MimKey
            && $request['userName'] === self::MimLogin
            && $request['senderName'] === 'MadinaNHC'
            && $request['campaignName'] === 'null'
            && $request['transactionType'] === 'T'
            && $request['mobileNumber'] === '8801720000088'
            && $request['message'] === self::BanglaText);
        $this->assertDatabaseHas('sms_logs', ['provider' => 'mim_sms', 'sent' => true, 'refused' => false, 'phone_masked' => '••••0088']);
    }

    public function test_mim_counts_as_sent_only_with_status_code_200_and_status_success(): void
    {
        $this->enableMim();
        Http::fake([self::MimUrl => Http::sequence()
            ->push(['statusCode' => '401', 'status' => 'Failed', 'responseResult' => 'Invalid API key'])
            ->push(['statusCode' => '200', 'status' => 'Failed', 'responseResult' => 'No balance'])
            ->push(['status' => 'Success'])
            ->push('not json at all')
            ->push(['statusCode' => '200', 'status' => 'Success'], 500)]);

        foreach (range(1, 5) as $attempt) {
            $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['sent'], 'reply '.$attempt);
        }
        Http::assertSentCount(5);
    }

    public function test_a_mim_refusal_tries_the_next_enabled_provider_and_keeps_secrets_out_of_the_log(): void
    {
        Http::fake([
            self::MimUrl => Http::response(['statusCode' => '401', 'status' => 'Failed', 'responseResult' => 'Bad key '.self::MimKey.' for '.self::MimLogin.' to 8801720000088']),
            self::GreenwebUrl => Http::response('Ok: SMS Sent Successfully'),
        ]);
        $this->enableMim();
        $this->enableGreenweb();

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertTrue($result['sent']);
        $this->assertSame('greenweb', $result['provider']);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('sms.mim_rejected', $log);
        $this->assertStringNotContainsString(self::MimKey, $log);
        $this->assertStringNotContainsString(self::MimLogin, $log);
        $this->assertStringNotContainsString('8801720000088', $log);
    }

    public function test_a_mim_connection_error_is_logged_without_secrets_and_never_escapes(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error for '.self::MimUrl.'?apiKey='.self::MimKey));
        $this->enableMim();

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertFalse($result['sent']);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('sms.gateway_failed', $log);
        $this->assertStringNotContainsString(self::MimKey, $log);
        $this->assertDatabaseHas('sms_logs', ['provider' => null, 'sent' => false]);
    }

    public function test_mim_goes_before_greenweb_when_both_are_on(): void
    {
        Http::fake([self::MimUrl => Http::response($this->mimAccepted())]);
        $this->enableGreenweb();
        $this->enableMim();

        $this->assertSame('mim_sms', app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['provider']);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === self::GreenwebUrl);
    }

    public function test_text_without_bangla_is_refused_before_mim_or_alpha_is_called(): void
    {
        Http::fake();
        $this->enableMim();
        $this->enableAlpha();

        $result = app(SmsGatewayClient::class)->send('01720000088', 'Your order was received');

        $this->assertTrue($result['refused']);
        Http::assertNothingSent();
        $this->assertDatabaseHas('sms_logs', ['refused' => true, 'sent' => false, 'provider' => null]);
    }

    public function test_mim_cannot_be_switched_on_without_its_login_and_sender_id(): void
    {
        $response = $this->saveProvider('sms', 'mim_sms', ['base_url' => self::MimUrl, 'api_key' => self::MimKey, 'username' => '', 'sender_id' => '']);

        $response->assertSessionHasErrors('username');
        $this->assertFalse(app(SmsGatewayClient::class)->anyEnabled());
    }

    public function test_mim_balance_is_read_from_the_documented_reply(): void
    {
        Http::fake([self::MimBalanceUrl => Http::response(['statusCode' => '200', 'status' => 'Success', 'message' => 'Success', 'data' => [['balance' => '37.45', 'expair_Date' => '03/06/2028']]])]);
        $this->enableMim();

        $result = app(SmsGatewayClient::class)->balance('mim_sms');

        $this->assertTrue($result['ok']);
        $this->assertSame('37.45', $result['balance']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && $request['userName'] === self::MimLogin && $request['apiKey'] === self::MimKey);
    }

    public function test_mim_balance_failure_shows_as_unavailable(): void
    {
        $this->enableMim();
        Http::fake([self::MimBalanceUrl => Http::sequence()
            ->push(['statusCode' => '401', 'status' => 'Failed'])
            ->pushFailedConnection('timed out')]);

        $this->assertFalse(app(SmsGatewayClient::class)->balance('mim_sms')['ok']);
        $this->assertFalse(app(SmsGatewayClient::class)->balance('mim_sms')['ok']);
        Http::assertSentCount(2);
    }

    public function test_alpha_send_counts_error_zero_as_sent(): void
    {
        Http::fake([self::AlphaUrl => Http::response(['error' => 0, 'msg' => 'Request successfully submitted', 'data' => ['request_id' => 1]])]);
        $this->enableAlpha('MadinaNHC');

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertTrue($result['sent']);
        $this->assertSame('alpha_sms', $result['provider']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->isForm()
            && $request['api_key'] === self::AlphaKey
            && $request['to'] === '8801720000088'
            && $request['msg'] === self::BanglaText
            && $request['sender_id'] === 'MadinaNHC'
            && ! str_contains($request->url(), self::AlphaKey));
    }

    public function test_alpha_refusal_is_not_counted_and_keeps_secrets_out_of_the_log(): void
    {
        $this->enableAlpha();
        Http::fake([self::AlphaUrl => Http::sequence()
            ->push(['error' => 417, 'msg' => 'Insufficient balance '.self::AlphaKey])
            ->push(['msg' => 'no error field'])
            ->push(['error' => 'x'])]);

        foreach (range(1, 3) as $attempt) {
            $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['sent'], 'reply '.$attempt);
        }

        Http::assertSentCount(3);
        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('sms.alpha_rejected', $log);
        $this->assertStringNotContainsString(self::AlphaKey, $log);
    }

    public function test_alpha_balance_success_and_failure(): void
    {
        $this->enableAlpha();
        Http::fake([self::AlphaBalanceUrl.'*' => Http::sequence()
            ->push(['error' => 0, 'msg' => 'Success', 'data' => ['balance' => '12.5000']])
            ->push(['error' => 405, 'msg' => 'Authorization required'])]);

        $this->assertSame('12.5000', app(SmsGatewayClient::class)->balance('alpha_sms')['balance']);
        $this->assertFalse(app(SmsGatewayClient::class)->balance('alpha_sms')['ok']);
    }

    public function test_setup_hints_cover_mim_activation_and_whitelisting_and_the_ses_settings(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertStringContainsString('Utility > Developer', __('portal.si_hint_mim_sms', [], $locale));
            $this->assertStringContainsString('email-smtp.<region>.amazonaws.com', __('portal.si_hint_smtp_host', [], $locale));
            $this->assertStringContainsString('587', __('portal.si_hint_smtp_port', [], $locale));
            $this->assertStringContainsString('TLS', __('portal.si_hint_smtp_encryption', [], $locale));
        }
        $this->assertStringContainsString('whitelist', __('portal.si_hint_mim_sms', [], 'en'));
        $this->assertStringContainsString('হোয়াইটলিস্ট', __('portal.si_hint_mim_sms', [], 'bn'));
    }

    private function saveSmtp(int $port, string $encryption): void
    {
        $this->saveProvider('mail', 'smtp', [
            'host' => 'email-smtp.ap-southeast-1.amazonaws.com',
            'port' => $port,
            'encryption' => $encryption,
            'username' => 'SES-SMTP-USER-TEST',
            'password' => 'ses-smtp-password-test',
            'from_address' => 'no-reply@madinanhc.com',
            'from_name' => 'Madina Natural Health Care',
        ])->assertSessionHasNoErrors();
        app(SmtpMailConfig::class)->apply();
    }

    public function test_ses_on_port_587_upgrades_with_starttls_even_when_the_server_says_smtps(): void
    {
        config(['mail.mailers.smtp.scheme' => 'smtps']);
        $this->saveSmtp(587, 'tls');

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtp', config('mail.mailers.smtp.scheme'));
        $transport = app('mail.manager')->mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $stream = $transport->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertFalse($stream->isTLS(), 'Port 587 starts plain and upgrades with STARTTLS.');
        $this->assertSame('email-smtp.ap-southeast-1.amazonaws.com', config('mail.mailers.smtp.host'));
    }

    public function test_ssl_on_port_465_connects_with_tls_from_the_start(): void
    {
        $this->saveSmtp(465, 'ssl');

        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $stream = app('mail.manager')->mailer('smtp')->getSymfonyTransport()->getStream();
        $this->assertInstanceOf(SocketStream::class, $stream);
        $this->assertTrue($stream->isTLS());
    }
}
