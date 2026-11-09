<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Super Admin alone can turn an MD or Chairman account off and on again. The owners'
 * accounts are not in the normal staff list, so the employees page has its own panel for this.
 */
class OwnerAccountStatusTest extends TestCase
{
    use RefreshDatabase;

    private User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->superAdmin = User::factory()->staff('super_admin')->create();
    }

    public function test_the_super_admin_gets_the_md_and_chairman_on_the_employees_page(): void
    {
        $md = User::factory()->staff('md')->create(['name' => 'The Managing Director']);
        $chairman = User::factory()->staff('chairman')->create(['name' => 'The Chairman', 'status' => 'suspended']);
        User::factory()->staff('finance')->create();

        $this->actingAs($this->superAdmin)->onPortal('sadmin')->get('/employees')
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.owner_accounts', 2)
                ->where('data.owner_accounts.0.id', $chairman->id)
                ->where('data.owner_accounts.0.status', 'suspended')
                ->where('data.owner_accounts.1.id', $md->id));
    }

    public function test_the_list_is_empty_for_the_md_and_the_chairman(): void
    {
        foreach (['md', 'chairman'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->get('/employees')
                ->assertInertia(fn (Assert $page) => $page->where('data.owner_accounts', []));
        }
    }

    public function test_the_super_admin_can_turn_an_md_off_and_on(): void
    {
        $md = User::factory()->staff('md')->create(['status' => 'active']);
        DB::table('sessions')->insert(['id' => str_repeat('b', 40), 'user_id' => $md->id, 'payload' => '', 'last_activity' => now()->timestamp]);

        $this->actingAs($this->superAdmin)->onPortal('sadmin')->from('/employees')
            ->patch('/commands/accounts/'.$md->id, ['status' => 'suspended', 'reason' => 'On leave for a while.'])
            ->assertRedirect('/employees')->assertSessionHasNoErrors();

        $this->assertSame('suspended', $md->fresh()->status);
        $this->assertDatabaseMissing('sessions', ['user_id' => $md->id]);
        $this->assertTrue(DB::table('audit_events')->where('action', 'account.updated')->where('subject', (string) $md->id)->exists());

        $this->actingAs($this->superAdmin)->onPortal('sadmin')->from('/employees')
            ->patch('/commands/accounts/'.$md->id, ['status' => 'active', 'reason' => 'Back at work.'])
            ->assertRedirect('/employees')->assertSessionHasNoErrors();

        $this->assertSame('active', $md->fresh()->status);
    }

    public function test_a_turned_off_chairman_cannot_use_the_app(): void
    {
        $chairman = User::factory()->staff('chairman')->create(['status' => 'suspended']);

        $this->actingAs($chairman)->onPortal('sadmin')->get('/employees')->assertRedirect();
    }

    public function test_only_the_super_admin_can_do_this(): void
    {
        $md = User::factory()->staff('md')->create(['status' => 'active']);
        $chairman = User::factory()->staff('chairman')->create(['status' => 'active']);
        $accountsStaff = User::factory()->staff('account_manager')->create();

        $this->actingAs($md)->onPortal('sadmin')->patch('/commands/accounts/'.$chairman->id, ['status' => 'suspended', 'reason' => 'No.'])->assertForbidden();
        $this->actingAs($chairman)->onPortal('sadmin')->patch('/commands/accounts/'.$md->id, ['status' => 'suspended', 'reason' => 'No.'])->assertForbidden();
        $this->actingAs($accountsStaff)->onPortal('manage')->patch('/commands/accounts/'.$md->id, ['status' => 'suspended', 'reason' => 'No.'])->assertForbidden();

        $this->assertSame('active', $md->fresh()->status);
        $this->assertSame('active', $chairman->fresh()->status);
    }
}
