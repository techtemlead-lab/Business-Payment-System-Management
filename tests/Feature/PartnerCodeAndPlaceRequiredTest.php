<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A partner always has a dealer code and a place, however the request reaches the server.
 */
class PartnerCodeAndPlaceRequiredTest extends TestCase
{
    use RefreshDatabase;

    public function test_editing_cannot_blank_a_partners_dealer_code(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-KEEP-01']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'dealer_code' => '',
            'reason' => 'Clearing the code',
        ])->assertSessionHasErrors('dealer_code');

        $this->assertSame('D-KEEP-01', $dealer->fresh()->dealer_code);
    }

    public function test_an_edit_that_does_not_send_the_code_still_saves(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'D-KEEP-02']);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'status' => 'suspended',
            'reason' => 'Paused for a check',
        ])->assertSessionHasNoErrors();

        $this->assertSame('suspended', $dealer->fresh()->status);
        $this->assertSame('D-KEEP-02', $dealer->fresh()->dealer_code);
    }

    public function test_a_staff_members_code_can_stay_blank(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active', 'dealer_code' => null]);

        $this->actingAs($owner)->onPortal('sadmin')->patch('/commands/accounts/'.$staff->id, [
            'dealer_code' => '',
            'reason' => 'Routine edit',
        ])->assertSessionHasNoErrors();
    }

    public function test_a_direct_request_cannot_create_a_partner_with_no_place(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        foreach (['dealer', 'sub_dealer'] as $role) {
            $email = $role.'-no-place@example.test';

            $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', [
                'role' => $role,
                'dealer_code' => 'NO-PLACE-'.$role,
                'name' => 'No Place Partner',
                'email' => $email,
                'phone' => $role === 'dealer' ? '01712000091' : '01712000092',
                'reason' => 'Direct request',
            ])->assertSessionHasErrors('place_code');

            $this->assertDatabaseMissing('users', ['email' => $email]);
        }
    }
}
