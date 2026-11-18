<?php

namespace Tests\Feature;

use App\Domain\Auth\TotpAuthenticator;
use App\Domain\Finance\TreasuryAccess;
use App\Domain\Security\SecuritySettings;
use App\Domain\Staff\LeadershipAccess;
use App\Models\User;
use App\Support\PhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SA-07 (D11), LC-07 (D13), LC-05, PW-04 and the TOTP / session items of SA-10.
 */
class OwnerAccessAndPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_md_and_chairman_see_payroll_only_until_the_super_admin_grants_more(): void
    {
        $superAdmin = User::factory()->staff('super_admin')->create();
        $md = User::factory()->staff('md')->create();
        $chairman = User::factory()->staff('chairman')->create();

        foreach ([$md, $chairman] as $leader) {
            $this->assertSame(['payroll'], $leader->capabilities());
            $this->assertSame([], $leader->safe()['owner_areas']);
            $this->assertFalse(TreasuryAccess::mayOpenPartners($leader));
            foreach (['/accounts', '/network-tree', '/data-cleanup', '/login-rules'] as $page) {
                $this->actingAs($leader)->onPortal('sadmin')->get($page)->assertForbidden();
            }
        }

        $this->actingAs($superAdmin)->onPortal('sadmin')->post('/commands/owner/leadership-access', [
            'version' => 0,
            'current_password' => 'password',
            'md_accounts' => '1', 'md_treasury' => '1', 'md_owner_tools' => '0',
            'chairman_accounts' => '0', 'chairman_treasury' => '0', 'chairman_owner_tools' => '0',
        ])->assertSessionHasNoErrors();
        LeadershipAccess::forget();

        $md->refresh();
        $this->assertSame(['accounts', 'treasury'], $md->safe()['owner_areas']);
        $this->assertTrue(TreasuryAccess::mayOpenPartners($md));
        $this->assertFalse(TreasuryAccess::mayOpenPartners($chairman->fresh()));
        $this->actingAs($md)->onPortal('sadmin')->get('/accounts')->assertOk();
        // Deleting records never opens for the MD, whatever is granted.
        $this->actingAs($md)->onPortal('sadmin')->get('/data-cleanup')->assertForbidden();
        $this->assertDatabaseHas('audit_events', ['action' => 'security.leadership_access_changed']);
    }

    public function test_only_the_super_admin_sets_what_the_md_and_chairman_open(): void
    {
        $md = User::factory()->staff('md')->create();

        $this->actingAs($md)->onPortal('sadmin')->post('/commands/owner/leadership-access', [
            'version' => 0, 'current_password' => 'password',
            'md_accounts' => '1', 'md_treasury' => '1', 'md_owner_tools' => '1',
            'chairman_accounts' => '1', 'chairman_treasury' => '1', 'chairman_owner_tools' => '1',
        ])->assertForbidden();
        $this->assertSame([], LeadershipAccess::current()['md']);
    }

    public function test_the_owner_switch_hides_the_numbers_of_directly_added_team_members(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->assertSame('01712345678', PhoneIdentity::forDownlineViewer('01712345678', 2, 1, 1));

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/team-phones', [
            'enabled' => '0', 'version' => 0, 'current_password' => 'password',
        ])->assertSessionHasNoErrors();

        $this->assertFalse(app(SecuritySettings::class)->teamPhonesVisible());
        $this->assertSame('••••5678', PhoneIdentity::forDownlineViewer('01712345678', 2, 1, 1, false));
        // The viewer's own number always shows.
        $this->assertSame('01712345678', PhoneIdentity::forDownlineViewer('01712345678', 1, null, 1, false));
    }

    public function test_personal_details_are_encrypted_in_the_database_and_read_back_plainly(): void
    {
        $partner = User::factory()->dealer()->create();
        $partner->forceFill(['profile' => [
            'date_of_birth' => '1980-05-04', 'father_name' => 'Abdul Karim', 'mother_name' => 'Rahima Begum',
            'village' => 'Char Rampur', 'post_office' => 'Rampur', 'place_code' => 'MNHC1001', 'shop_name' => 'Karim Pharmacy',
        ]])->save();

        $raw = json_decode((string) DB::table('users')->where('id', $partner->id)->value('profile'), true);
        foreach (['date_of_birth', 'father_name', 'mother_name', 'village', 'post_office'] as $field) {
            $this->assertStringStartsWith('enc:', $raw[$field], $field);
        }
        // Place and shop stay plain, so the place queries keep working.
        $this->assertSame('MNHC1001', $raw['place_code']);
        $this->assertSame('Karim Pharmacy', $raw['shop_name']);

        $profile = $partner->fresh()->profile;
        $this->assertSame('1980-05-04', $profile['date_of_birth']);
        $this->assertSame('Rahima Begum', $profile['mother_name']);
        $this->assertSame(1, User::query()->whereKey($partner->id)->whereNotNull('profile->date_of_birth')->count());
    }

    public function test_an_authenticator_code_works_only_once(): void
    {
        $staff = User::factory()->staff('finance')->create();
        $totp = app(TotpAuthenticator::class);
        $secret = $totp->generateSecret();
        $time = 1_800_000_000;
        $code = $this->codeAt($totp, $secret, $time);

        $this->assertTrue($totp->verifyOnce($staff, $secret, $code, $time));
        $this->assertFalse($totp->verifyOnce($staff, $secret, $code, $time + 20));
    }

    public function test_a_session_from_one_portal_is_refused_on_another(): void
    {
        // An office account whose session was opened on the owner panel, replayed on the staff panel.
        $staff = User::factory()->staff('finance')->create();

        $this->actingAs($staff)->withSession(['session_portal' => 'sadmin'])
            ->onPortal('manage')->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_an_office_session_ends_after_its_fixed_lifetime(): void
    {
        $staff = User::factory()->staff('finance')->create();

        $this->actingAs($staff)->withSession([
            'session_portal' => 'manage',
            'session_started_at' => now()->subHours(13)->getTimestamp(),
        ])->onPortal('manage')->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_the_public_forgot_password_page_asks_dealer_or_sub_dealer_first(): void
    {
        $page = $this->onPortal('public')->get('/forgot-password')->assertOk()->assertSee('/forgot-password', false)->getContent();
        // The public site starts in Bangla; either language shows the chooser title.
        $this->assertTrue(str_contains((string) $page, __('security.forgot_choose_title', [], 'bn')) || str_contains((string) $page, __('security.forgot_choose_title', [], 'en')));
    }

    private function codeAt(TotpAuthenticator $totp, string $secret, int $time): string
    {
        // The code the app would show at that moment, worked out with the authenticator's own method.
        return (fn (): string => $this->hotp($this->base32Decode($secret), intdiv($time, self::Period)))->call($totp);
    }
}
