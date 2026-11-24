<?php

namespace Tests\Feature;

use App\Domain\Integrations\SmsGatewayClient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * RegisterCamp is never called for real: every request goes to Http::fake().
 * The token and sender ID here are made up for the tests.
 */
class RegisterCampSmsGatewayTest extends TestCase
{
    use RefreshDatabase;

    private const SendUrl = 'https://sms.registercamp.com/api/v1/send-sms.php';

    private const BalanceUrl = 'https://sms.registercamp.com/api/v1/check-balance.php';

    private const Token = 'registercamp-test-token-not-real';

    private const SenderId = '8800000000000';

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

    private function enableRegisterCamp(string $baseUrl = self::SendUrl, string $senderId = self::SenderId): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'registercamp',
            'version' => (int) (DB::table('business_settings')->where('key', 'service_integrations')->value('version') ?? 0),
            'enabled' => true,
            'base_url' => $baseUrl,
            'balance_url' => self::BalanceUrl,
            'api_key' => self::Token,
            'sender_id' => $senderId,
            'reason' => 'The owner chose this service.',
        ])->assertSessionHasNoErrors();
    }

    public function test_send_posts_the_documented_form_and_counts_a_success_reply_as_sent(): void
    {
        Http::fake([self::SendUrl => Http::response(['status' => 'success', 'message' => 'SMS sent.'])]);
        $this->enableRegisterCamp();

        $result = app(SmsGatewayClient::class)->send('01720000088', self::BanglaText);

        $this->assertTrue($result['sent']);
        $this->assertSame('registercamp', $result['provider']);
        Http::assertSent(fn (Request $request): bool => $request->url() === self::SendUrl
            && $request->method() === 'POST'
            && $request['token'] === self::Token
            && $request['sender_id'] === self::SenderId
            && $request['recipient'] === '8801720000088'
            && $request['message'] === self::BanglaText
            && $request['transtype'] === 'T'
            && ! str_contains($request->url(), self::Token));
        $this->assertDatabaseHas('sms_logs', ['provider' => 'registercamp', 'sent' => true, 'refused' => false, 'phone_masked' => '••••0088']);
    }

    public function test_only_a_success_status_in_a_json_reply_counts_as_sent(): void
    {
        $this->enableRegisterCamp();
        Http::fake([self::SendUrl => Http::sequence()
            ->push(['status' => 'error', 'message' => 'Invalid token'])
            ->push(['message' => 'no status at all'])
            ->push('not json at all')
            ->push(['status' => 'success'], 500)]);

        foreach (range(1, 4) as $attempt) {
            $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['sent'], 'reply '.$attempt);
        }
        Http::assertSentCount(4);
    }

    public function test_a_refusal_keeps_the_token_and_the_number_out_of_the_log(): void
    {
        Http::fake([self::SendUrl => Http::response(['status' => 'error', 'message' => 'Bad '.self::Token.' for 8801720000088'])]);
        $this->enableRegisterCamp();

        $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['sent']);

        $log = implode("\n", $this->logged);
        $this->assertStringContainsString('sms.registercamp_rejected', $log);
        $this->assertStringNotContainsString(self::Token, $log);
        $this->assertStringNotContainsString('8801720000088', $log);
    }

    public function test_without_a_sender_id_nothing_is_sent(): void
    {
        Http::fake();
        $this->enableRegisterCamp(senderId: '');

        $this->assertFalse(app(SmsGatewayClient::class)->send('01720000088', self::BanglaText)['sent']);
        Http::assertNothingSent();
    }

    public function test_balance_reads_the_amount_and_refuses_an_error_reply(): void
    {
        $this->enableRegisterCamp();
        Http::fake([self::BalanceUrl.'*' => Http::sequence()
            ->push(['status' => 'success', 'message' => 'Balance fetched successfully.', 'data' => ['balance' => 160.61, 'formatted_balance' => '৳ 160.61', 'sms_rate' => 0.39, 'currency' => 'BDT']])
            ->push(['status' => 'error', 'message' => 'Invalid token'])]);

        $first = app(SmsGatewayClient::class)->balance('registercamp');
        $this->assertTrue($first['ok']);
        $this->assertSame('160.61', $first['balance']);
        $this->assertFalse(app(SmsGatewayClient::class)->balance('registercamp')['ok']);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && $request['token'] === self::Token);
    }

    public function test_the_gateway_address_may_only_be_registercamps_own(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'registercamp',
            'version' => 0,
            'enabled' => true,
            'base_url' => 'https://attacker.example.com/send-sms.php',
            'balance_url' => self::BalanceUrl,
            'api_key' => self::Token,
            'sender_id' => self::SenderId,
            'reason' => 'Point the token at another server.',
        ])->assertSessionHasErrors(['base_url']);
    }
}
