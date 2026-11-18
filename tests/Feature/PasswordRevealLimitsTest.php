<?php

namespace Tests\Feature;

use App\Domain\Staff\LeadershipAccess;
use App\Models\User;
use App\Support\PasswordVault;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SA-02: reading a password back is limited to dealer and sub-dealer accounts, needs the owner's own
 * password, has a daily cap, tells the other owners, and the copy uses its own key.
 */
class PasswordRevealLimitsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $role = 'super_admin'): User
    {
        return User::factory()->staff($role)->create();
    }

    private function partner(string $password = 'PartnerPass77'): User
    {
        $partner = User::factory()->dealer()->create(['phone' => '01712345111']);
        $partner->storeAdminManagedPassword($password);

        return $partner->fresh();
    }

    private function reveal(User $owner, User $target, array $payload = [])
    {
        return $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$target->id.'/reveal-password', $payload);
    }

    public function test_the_md_types_their_own_password_once_before_a_partner_password_is_shown(): void
    {
        // D11: the MD reads partner passwords only with the accounts grant from the Super Admin.
        LeadershipAccess::save($this->owner(), ['md' => ['accounts']], LeadershipAccess::current()['version']);
        $md = $this->owner('md');
        $partner = $this->partner();

        $this->reveal($md, $partner)->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->reveal($md, $partner, ['current_password' => 'wrong-one'])->assertUnprocessable()->assertJsonValidationErrors('current_password');
        $this->assertDatabaseMissing('audit_events', ['action' => 'account.password_revealed']);

        $this->reveal($md, $partner, ['current_password' => 'password'])->assertOk()->assertJsonPath('data.password', 'PartnerPass77');
        // Within the next few minutes no password is asked again.
        $this->reveal($md, $partner)->assertOk()->assertJsonPath('data.password', 'PartnerPass77');

        $this->travel(6)->minutes();
        $this->reveal($md, $partner)->assertUnprocessable();
    }

    public function test_the_owner_is_never_asked_to_confirm_but_every_view_is_still_recorded(): void
    {
        $owner = $this->owner();
        $partner = $this->partner();

        // CR-10: the one owner is never stopped by a confirmation step.
        $this->reveal($owner, $partner)->assertOk()->assertJsonPath('data.password', 'PartnerPass77');
        $this->assertDatabaseHas('audit_events', ['action' => 'account.password_revealed', 'actor_id' => $owner->id]);
    }

    public function test_reading_passwords_stops_at_the_daily_cap(): void
    {
        Config::set('madina.password_reveal.per_day', 2);
        $owner = $this->owner();
        $partner = $this->partner();

        $this->reveal($owner, $partner, ['current_password' => 'password'])->assertOk();
        $this->reveal($owner, $partner)->assertOk();
        $this->reveal($owner, $partner)->assertStatus(429);
        $this->assertDatabaseHas('audit_events', ['action' => 'account.password_reveal_capped']);
    }

    public function test_the_other_owners_are_told_when_a_password_is_shown(): void
    {
        $owner = $this->owner();
        $md = $this->owner('md');
        $chairman = $this->owner('chairman');
        $partner = $this->partner();

        $this->reveal($owner, $partner, ['current_password' => 'password'])->assertOk();

        $this->assertSame(1, DB::table('account_notices')->where('user_id', $md->id)->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $chairman->id)->count());
        $this->assertSame(0, DB::table('account_notices')->where('user_id', $owner->id)->count());
    }

    public function test_no_readable_copy_is_kept_for_office_accounts_even_when_an_owner_sets_it(): void
    {
        $owner = $this->owner();
        $staff = User::factory()->staff('finance')->create(['phone' => '01712345222']);

        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/accounts/'.$staff->id.'/reset-password', ['password' => 'OfficeChosen91'])
            ->assertOk()
            ->assertJsonPath('data.password', 'OfficeChosen91');

        $this->assertNull($staff->fresh()->adminVisiblePassword());
        $this->assertNull(DB::table('users')->where('id', $staff->id)->value('admin_password_cipher'));
        $this->reveal($owner, $staff, ['current_password' => 'password'])->assertForbidden();
    }

    public function test_old_readable_office_copies_are_wiped_and_partner_copies_kept(): void
    {
        $staff = User::factory()->staff('finance')->create();
        $md = User::factory()->staff('md')->create();
        $partner = $this->partner();
        DB::table('users')->whereIn('id', [$staff->id, $md->id])->update(['admin_password_cipher' => Crypt::encryptString('OldCopy1')]);

        (require database_path('migrations/2026_11_18_151628_forget_readable_office_passwords.php'))->up();

        $this->assertNull(DB::table('users')->where('id', $staff->id)->value('admin_password_cipher'));
        $this->assertNull(DB::table('users')->where('id', $md->id)->value('admin_password_cipher'));
        $this->assertSame('PartnerPass77', $partner->fresh()->adminVisiblePassword());
    }

    public function test_the_copy_uses_the_vault_key_and_older_app_key_copies_are_moved_to_it(): void
    {
        $partner = User::factory()->dealer()->create();
        DB::table('users')->where('id', $partner->id)->update(['admin_password_cipher' => Crypt::encryptString('WrittenBefore1')]);

        Config::set('madina.password_vault.key', PasswordVault::generateKey());
        $this->app->forgetInstance(PasswordVault::class);
        $vault = app(PasswordVault::class);
        $this->assertTrue($vault->configured());

        // A copy written before the vault key existed still opens.
        $this->assertSame('WrittenBefore1', $partner->fresh()->adminVisiblePassword());

        $this->artisan('madina:password-vault-rekey')->assertSuccessful();
        $payload = (string) DB::table('users')->where('id', $partner->id)->value('admin_password_cipher');
        $this->assertTrue($vault->usesVaultKey($payload));
        $this->assertSame('WrittenBefore1', $partner->fresh()->adminVisiblePassword());

        // APP_KEY alone no longer opens it.
        $this->expectException(DecryptException::class);
        Crypt::decryptString($payload);
    }
}
