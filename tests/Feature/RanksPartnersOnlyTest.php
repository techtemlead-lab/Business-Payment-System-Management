<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** The ranks list is about partners. Office staff never belong on it. */
class RanksPartnersOnlyTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->owner = User::factory()->staff()->create();
    }

    public function test_adding_a_staff_member_makes_no_rank_row(): void
    {
        $email = 'new-staff-'.Str::random(6).'@example.test';

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/employees')->post('/commands/accounts', [
            'role' => 'employee',
            'name' => 'New staff',
            'email' => $email,
            'phone' => '01712345670',
            'status' => 'active',
            'reason' => 'Joined the office',
            'permissions' => ['orders'],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $newStaff = User::where('email', $email)->sole();
        $this->assertDatabaseMissing('dealer_ranks', ['user_id' => $newStaff->id]);
    }

    public function test_ranks_page_leaves_out_staff_who_already_have_a_rank_row(): void
    {
        $dealer = User::factory()->dealer()->create(['name' => 'Real dealer', 'status' => 'active']);
        $staff = User::factory()->staff('support')->create(['name' => 'Office person']);
        foreach ([$dealer, $staff] as $person) {
            DB::table('dealer_ranks')->insert(['user_id' => $person->id, 'current_rank' => 'mo', 'lifetime_purchase_total' => 0, 'monthly_purchase_total' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }

        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));
        $data = $this->actingAs($this->owner)->onPortal('sadmin')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/ranks')->assertOk()->json('props.data');

        $this->assertSame(['Real dealer'], array_column($data['ranks']['data'], 'name'));
        $this->assertSame(1, $data['stats']['mo_count']);
    }
}
