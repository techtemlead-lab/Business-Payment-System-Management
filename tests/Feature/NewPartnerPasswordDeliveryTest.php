<?php

namespace Tests\Feature;

use App\Domain\Notifications\AccountCreatedSms;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * When the SMS with a new partner's password cannot be sent, the partner gets a link to set a
 * password by email. Nobody has to be shown or told a plain password.
 */
class NewPartnerPasswordDeliveryTest extends TestCase
{
    use RefreshDatabase;

    private const MimUrl = 'https://api.mimsms.com/api/V2/SMS';

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        Notification::fake();
        $this->owner = User::factory()->staff()->create(['status' => 'active']);
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    public function test_no_link_is_sent_when_the_sms_goes_out(): void
    {
        $this->createPartner()->assertSessionHas('success', __('portal.acct_partner_created_sent', $this->flashValues()));

        Notification::assertNothingSent();
    }

    public function test_a_refused_sms_sends_a_reset_link_and_tells_the_office(): void
    {
        $this->enableMim();
        Http::fake([self::MimUrl => Http::response(['statusCode' => '401', 'status' => 'Failed', 'responseResult' => 'Bad key'])]);

        $this->createPartner()->assertRedirect('/accounts')
            ->assertSessionHas('success', __('portal.acct_partner_created_not_sent', $this->flashValues()));

        Notification::assertSentTo(User::where('email', 'partner@example.com')->firstOrFail(), ResetPassword::class);
    }

    public function test_the_password_is_never_emailed_when_the_sms_fails(): void
    {
        Mail::fake();
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'mail',
            'provider' => 'smtp',
            'version' => (int) (DB::table('business_settings')->where('key', 'service_integrations')->value('version') ?? 0),
            'enabled' => true,
            'reason' => 'The owner turned email on.',
            'host' => 'smtp.example.test',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'mail-user',
            'password' => 'mail-password-not-real',
            'from_address' => 'office@example.test',
            'from_name' => 'Madina',
        ])->assertSessionHasNoErrors();
        config(['madina.sms.driver' => 'unsupported']);
        $partner = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01712340000']);

        $this->assertFalse(app(AccountCreatedSms::class)->send($partner, 'Plain-Password-1'));

        Mail::assertNothingOutgoing();
    }

    public function test_no_sms_service_on_the_live_server_counts_as_not_sent(): void
    {
        config(['madina.sms.driver' => 'log']);
        $this->app->detectEnvironment(fn (): string => 'production');
        $partner = User::factory()->dealer()->create(['status' => 'active', 'phone' => '01712340001']);

        $this->assertFalse(app(AccountCreatedSms::class)->send($partner, 'Plain-Password-1'));
    }

    private function enableMim(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms',
            'provider' => 'mim_sms',
            'version' => (int) (DB::table('business_settings')->where('key', 'service_integrations')->value('version') ?? 0),
            'enabled' => true,
            'reason' => 'The owner chose this service.',
            'base_url' => self::MimUrl,
            'balance_url' => 'https://api.mimsms.com/api/V2/BalanceCheck',
            'api_key' => 'mim-test-key-not-real',
            'username' => 'panel-login@madina-tests.com',
            'sender_id' => 'Madina',
        ])->assertSessionHasNoErrors();
    }

    private function createPartner(): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->from('/accounts/new')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19901234567890123',
            'date_of_birth' => '1990-01-15',
            'name' => 'Md. Partner',
            'name_bn' => 'মোঃ পার্টনার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-102',
            'refer_code' => 'REF-DELIVERY',
            'phone' => '01712345678',
            'email' => 'partner@example.com',
            'reason' => 'New partner for the test.',
        ]);
    }

    /** @return array{role: string, code: string, phone: string, email: string} */
    private function flashValues(): array
    {
        return ['role' => __('portal.acct_role_dealer'), 'code' => 'MNHC-102', 'phone' => '01712345678', 'email' => 'partner@example.com'];
    }
}
