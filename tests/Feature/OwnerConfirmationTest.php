<?php

namespace Tests\Feature;

use App\Domain\Security\OwnerConfirmation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OwnerConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.preview_mode' => false, 'madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345', 'phone' => '01712345678']);
    }

    public function test_the_password_is_asked_unless_the_owner_chooses_otherwise(): void
    {
        $this->assertSame(['mode' => 'password', 'version' => 0], app(OwnerConfirmation::class)->current());

        $this->postNotice(['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');
        $this->postNotice([])->assertSessionHasErrors('current_password');
        $this->postNotice(['current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'security.password_reset_notice_changed']);
    }

    public function test_when_off_nothing_is_asked(): void
    {
        $this->chooseMode('off');

        $this->assertSame('none', app(OwnerConfirmation::class)->method($this->owner));
        $this->postNotice([])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'security.password_reset_notice_changed']);
    }

    public function test_the_owner_contact_form_follows_the_same_choice(): void
    {
        $this->chooseMode('off');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/contact', ['email' => 'new-owner@example.test'])->assertSessionHasNoErrors();

        $this->assertSame('new-owner@example.test', $this->owner->fresh()->email);
    }

    public function test_in_code_mode_a_code_sent_to_the_phone_is_needed_and_works_once(): void
    {
        $this->chooseMode('sms');
        $this->assertSame('code', app(OwnerConfirmation::class)->method($this->owner));

        $this->postNotice(['current_password' => 'OwnerPassword12345'])->assertSessionHasErrors('confirm_code');
        $this->postNotice(['confirm_code' => '123456'])->assertSessionHasErrors('confirm_code');

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/confirmation-code')->assertSessionHasNoErrors();
        $code = $this->sentCode();
        $this->postNotice(['confirm_code' => $this->wrongCode($code)])->assertSessionHasErrors('confirm_code');
        $this->postNotice(['confirm_code' => $code])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'security.password_reset_notice_changed']);

        $this->postNotice(['confirm_code' => $code, 'version' => 1])->assertSessionHasErrors('confirm_code');
    }

    public function test_when_no_code_can_be_sent_the_owner_is_told_and_the_password_works(): void
    {
        $this->chooseMode('sms');
        config(['madina.sms.driver' => 'log']);

        $this->assertFalse(app(OwnerConfirmation::class)->smsWorks($this->owner));
        $this->assertSame('password', app(OwnerConfirmation::class)->method($this->owner));
        $this->assertTrue(app(OwnerConfirmation::class)->payload($this->owner)['code_fell_back']);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/confirmation-code')->assertSessionHasErrors('confirm_code');
        $this->postNotice(['current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();
    }

    public function test_a_failed_send_lets_the_owner_use_the_password_for_a_while(): void
    {
        Http::preventStrayRequests();
        config(['madina.sms.driver' => 'log']);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/operations/integrations/providers', [
            'group' => 'sms', 'provider' => 'greenweb', 'version' => 0, 'enabled' => true,
            'base_url' => 'https://api.bdbulksms.net/api.php', 'balance_url' => 'https://api.bdbulksms.net/g_api.php',
            'api_key' => 'greenweb-test-token-7f3a9', 'reason' => 'Greenweb is the SMS service the owner chose.',
        ])->assertSessionHasNoErrors();
        $this->chooseMode('sms');
        $this->assertSame('code', app(OwnerConfirmation::class)->method($this->owner));

        // The SMS service answers with an error, so nothing reaches the phone.
        Http::fake(['*' => Http::response('Error', 500)]);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/confirmation-code')->assertSessionHasErrors('confirm_code');

        $this->assertSame('password', app(OwnerConfirmation::class)->method($this->owner));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $this->owner->id, 'action' => 'security.owner_code_not_sent']);
        $this->postNotice(['current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();
    }

    public function test_the_code_mode_cannot_be_saved_while_texts_do_not_work_unless_the_owner_confirms(): void
    {
        config(['madina.sms.driver' => 'log']);

        $this->saveMode('sms', ['current_password' => 'OwnerPassword12345'])->assertSessionHasErrors('accept_no_sms');
        $this->assertSame('password', app(OwnerConfirmation::class)->current()['mode']);

        $this->saveMode('sms', ['current_password' => 'OwnerPassword12345', 'accept_no_sms' => '1'])->assertSessionHasNoErrors();
        $this->assertSame('sms', app(OwnerConfirmation::class)->current()['mode']);
        // Texts still do not work, so the owner is not locked out: the password is what is asked.
        $this->assertSame('password', app(OwnerConfirmation::class)->method($this->owner));
    }

    public function test_changing_the_choice_needs_the_current_confirmation_and_is_audited(): void
    {
        $this->saveMode('off', [])->assertSessionHasErrors('current_password');
        $this->saveMode('off', ['current_password' => 'wrong-password'])->assertSessionHasErrors('current_password');
        $this->assertSame('password', app(OwnerConfirmation::class)->current()['mode']);

        $this->saveMode('off', ['current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();

        $audit = DB::table('audit_events')->where('action', 'security.owner_confirmation_changed')->sole();
        $this->assertSame(['mode' => 'password'], json_decode($audit->metadata, true)['before']);
        $this->assertSame(['mode' => 'off'], json_decode($audit->metadata, true)['after']);
    }

    public function test_a_stale_version_and_an_unknown_mode_are_refused(): void
    {
        $this->saveMode('off', ['current_password' => 'OwnerPassword12345', 'version' => 5])->assertSessionHasErrors('version');
        $this->saveMode('sometimes', ['current_password' => 'OwnerPassword12345'])->assertSessionHasErrors('mode');
    }

    public function test_only_owners_can_use_these_pages_and_the_md_may_too(): void
    {
        $manager = User::factory()->staff('account_manager')->create();
        $status = $this->actingAs($manager)->onPortal('manage')->post('/commands/owner/confirmation', ['mode' => 'off', 'version' => 0])->getStatusCode();
        $this->assertContains($status, [403, 404]);
        $this->assertSame('password', app(OwnerConfirmation::class)->current()['mode']);

        $md = User::factory()->staff('md')->create(['password' => 'MdPassword1234567']);
        $this->actingAs($md)->onPortal('sadmin')->post('/commands/owner/confirmation', ['mode' => 'off', 'version' => 0, 'current_password' => 'MdPassword1234567'])->assertSessionHasNoErrors();
        $this->assertSame('off', app(OwnerConfirmation::class)->current()['mode']);
    }

    public function test_the_login_rules_page_shows_the_choice_and_other_pages_do_not_load_it(): void
    {
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/login-rules')
            ->assertInertia(fn ($page) => $page->where('securityControls.owner_confirmation.mode', 'password')->where('securityControls.owner_confirmation.method', 'password'));
        $this->actingAs($this->owner)->onPortal('sadmin')->get('/orders')
            ->assertInertia(fn ($page) => $page->where('securityControls.owner_confirmation', null));
    }

    /** @param array<string, mixed> $extra */
    private function postNotice(array $extra)
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/password-reset-notice', $extra + ['enabled' => '0', 'version' => 0]);
    }

    /** @param array<string, mixed> $extra */
    private function saveMode(string $mode, array $extra)
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/owner/confirmation', $extra + ['mode' => $mode, 'version' => 0]);
    }

    private function chooseMode(string $mode): void
    {
        $this->saveMode($mode, ['current_password' => 'OwnerPassword12345'])->assertSessionHasNoErrors();
    }

    private function sentCode(): string
    {
        $sent = config('madina.sms.sent');
        $this->assertNotEmpty($sent);
        $this->assertSame('owner.confirm', end($sent)['event']);
        preg_match('/\d{6}/', end($sent)['message'], $match);

        return $match[0];
    }

    private function wrongCode(string $code): string
    {
        return $code === '111111' ? '222222' : '111111';
    }
}
