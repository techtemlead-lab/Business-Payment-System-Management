<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Only the owners (Super Admin, MD, Chairman) switch company-stock dispensing on or off for an
 * account. Staff with the verify permission still switch prescribing on or off for partners.
 */
class OwnerOnlyCompanyStockSwitchTest extends TestCase
{
    use RefreshDatabase;

    private const Registration = ['registration_type' => 'bmdc', 'registration_number' => 'A-777'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function verifier(): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts', 'prescriber_verify']]]);
    }

    public function test_staff_with_the_verify_permission_cannot_touch_company_stock(): void
    {
        $account = User::factory()->staff('employee')->create(['can_prescribe' => true]);
        $other = User::factory()->staff('finance')->create();
        $verifier = $this->verifier();

        $this->actingAs($verifier)->onPortal('manage')->post('/commands/accounts/'.$other->id.'/prescriber', self::Registration)->assertForbidden();
        $this->actingAs($verifier)->onPortal('manage')->delete('/commands/accounts/'.$account->id.'/prescriber')->assertForbidden();
        $this->actingAs($verifier)->onPortal('manage')->getJson('/commands/accounts/'.$other->id.'/prescriber')->assertForbidden();
        $this->actingAs($verifier)->onPortal('manage')->getJson('/commands/prescribers/office-accounts')->assertForbidden();

        $this->assertFalse($other->fresh()->can_prescribe);
        $this->assertTrue($account->fresh()->can_prescribe);
    }

    public function test_staff_with_the_verify_permission_still_handle_partners(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($this->verifier())->onPortal('manage')->post('/commands/accounts/'.$dealer->id.'/prescriber', self::Registration)
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue($dealer->fresh()->can_prescribe);
    }

    public function test_every_owner_can_switch_company_stock_on(): void
    {
        foreach (['super_admin', 'md', 'chairman'] as $role) {
            $account = User::factory()->staff('finance')->create();

            $this->actingAs(User::factory()->staff($role)->create())->onPortal('sadmin')->post('/commands/accounts/'.$account->id.'/prescriber', self::Registration)
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertTrue($account->fresh()->can_prescribe);
        }
    }
}
