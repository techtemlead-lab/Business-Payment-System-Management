<?php

namespace Tests\Feature;

use App\Domain\Security\SecuritySettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PasswordResetOwnerNoticeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
    }

    public function test_owners_get_a_notice_without_the_password_when_staff_reset_one(): void
    {
        $superAdmin = User::factory()->staff('super_admin')->create();
        $md = User::factory()->staff('md')->create();
        $suspendedChairman = User::factory()->staff('chairman')->suspended()->create();
        $manager = User::factory()->staff('account_manager')->create(['name' => 'Rafiq Manager']);
        $dealer = User::factory()->dealer()->create(['name' => 'Karim Dealer', 'phone' => '01712345444']);

        $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$dealer->id.'/reset-password')
            ->assertOk();

        $newPassword = $dealer->fresh()->adminVisiblePassword();
        $this->assertNotNull($newPassword);
        foreach ([$superAdmin, $md] as $owner) {
            $notice = DB::table('account_notices')->where('user_id', $owner->id)->sole();
            $this->assertStringContainsString('Rafiq Manager', $notice->body);
            $this->assertStringContainsString('Karim Dealer', $notice->body);
            $this->assertStringNotContainsString($newPassword, $notice->title.$notice->body);
        }
        $this->assertDatabaseMissing('account_notices', ['user_id' => $suspendedChairman->id]);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $manager->id]);
    }

    public function test_no_notice_when_the_owner_switch_is_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['password' => 'OwnerPassword12345']);
        $manager = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01712345555']);

        $this->assertTrue(app(SecuritySettings::class)->passwordResetNoticeEnabled());
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/password-reset-notice', [
            'enabled' => '0',
            'version' => 0,
            'current_password' => 'OwnerPassword12345',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(app(SecuritySettings::class)->passwordResetNoticeEnabled());
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'security.password_reset_notice_changed']);

        $this->actingAs($manager)->onPortal('manage')
            ->postJson('/commands/accounts/'.$dealer->id.'/reset-password')
            ->assertOk();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_an_owner_resetting_a_password_sends_no_notice(): void
    {
        User::factory()->staff('super_admin')->create();
        $md = User::factory()->staff('md')->create();
        $dealer = User::factory()->dealer()->create(['phone' => '01712345666']);

        $this->actingAs($md)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$dealer->id.'/reset-password')
            ->assertOk();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_only_owners_can_change_the_switch(): void
    {
        $manager = User::factory()->staff('account_manager')->create(['password' => 'ManagerPassword123']);

        $this->actingAs($manager)->onPortal('manage')->post('/commands/owner/password-reset-notice', [
            'enabled' => '0',
            'version' => 0,
            'current_password' => 'ManagerPassword123',
        ])->assertForbidden();

        $this->assertTrue(app(SecuritySettings::class)->passwordResetNoticeEnabled());
        $this->assertDatabaseMissing('business_settings', ['key' => SecuritySettings::PasswordResetNoticeKey]);
    }

    public function test_owner_security_page_carries_the_switch(): void
    {
        $owner = User::factory()->staff('chairman')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/security')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityControls.password_reset_notice.enabled', true)
                ->where('securityControls.password_reset_notice.version', 0));
    }
}
