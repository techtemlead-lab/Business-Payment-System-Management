<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Owner's decision: no-condition salary, royalty and rank reward can be granted only by owners. */
class OnlyOwnersGrantNoConditionBenefitsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['unconditional_salary' => 1, 'reason' => 'Owner decided this for the partner'];
    }

    public function test_a_finance_approver_cannot_grant_no_condition_benefits(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $dealer = User::factory()->dealer()->create();

        $this->assertTrue($finance->canAct('finance', 'approve'));
        $this->actingAs($finance)->onPortal('manage')->post('/commands/accounts/'.$dealer->id.'/benefit-controls', $this->payload())->assertForbidden();

        $this->assertArrayNotHasKey('benefit_controls', $dealer->fresh()->profile ?? []);
    }

    public function test_every_owner_can_grant_no_condition_benefits(): void
    {
        foreach (['super_admin', 'md', 'chairman'] as $role) {
            $owner = User::factory()->staff($role)->create();
            $dealer = User::factory()->dealer()->create();

            $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts/'.$dealer->id.'/benefit-controls', $this->payload())
                ->assertRedirect()->assertSessionHasNoErrors();

            $this->assertTrue($dealer->fresh()->profile['benefit_controls']['unconditional_salary']);
        }
    }

    public function test_the_reason_is_compulsory_for_the_md_from_the_start_but_never_for_the_super_admin(): void
    {
        $dealer = User::factory()->dealer()->create();
        $url = '/commands/accounts/'.$dealer->id.'/benefit-controls';

        $this->actingAs(User::factory()->staff('md')->create())->onPortal('sadmin')->post($url, ['unconditional_salary' => 1])
            ->assertSessionHasErrors('reason');
        $this->assertArrayNotHasKey('benefit_controls', $dealer->fresh()->profile ?? []);

        $this->actingAs(User::factory()->staff('super_admin')->create())->onPortal('sadmin')->post($url, ['unconditional_salary' => 1])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue($dealer->fresh()->profile['benefit_controls']['unconditional_salary']);
    }
}
