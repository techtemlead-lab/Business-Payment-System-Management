<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Commissions\CommissionQualification;
use App\Models\DealerRank;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerBenefitControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_save_unconditional_benefit_controls_directly(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'unconditional_rank_royalty' => 1,
                'forced_royalty_rank' => 'mo',
                'unconditional_rank_incentive' => 1,
                'note' => 'Founder exception',
                'reason' => 'Approved by owner for launch support',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Unconditional benefit controls saved for '.$dealer->name.'.');

        $dealer->refresh();
        $controls = PartnerBenefitControls::for($dealer);
        $this->assertTrue($controls['unconditional_salary']);
        $this->assertTrue($controls['unconditional_rank_royalty']);
        $this->assertSame('mo', $controls['forced_royalty_rank']);
        $this->assertTrue($controls['unconditional_rank_incentive']);
        $this->assertDatabaseHas('dealer_ranks', ['user_id' => $dealer->id]);
    }

    public function test_benefit_control_flash_follows_admin_bangla_locale(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'name' => 'Rahim Dealer']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$dealer->id.'/benefit-controls', [
                'unconditional_salary' => 1,
                'reason' => 'Owner override for Bangla flash coverage',
            ])
            ->assertRedirect()
            ->assertSessionHas('success', 'Rahim Dealer-এর জন্য শর্তহীন বেনিফিট কন্ট্রোল সংরক্ষণ হয়েছে।');
    }

    public function test_unconditional_rank_royalty_forces_paid_as_rank_without_volume(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DealerRank::query()->create(['user_id' => $dealer->id, 'current_rank' => 'dealer']);
        app(PartnerBenefitControls::class)->save(
            User::factory()->create(['role' => 'super_admin']),
            $dealer,
            [
                'unconditional_rank_royalty' => true,
                'forced_royalty_rank' => 'am',
                'reason' => 'Owner override for AM royalty bucket',
            ],
        );

        $paidAs = app(CommissionQualification::class)->paidAs($dealer->id);
        $this->assertSame('am', $paidAs['paid_as_rank']);
        $this->assertSame('admin_unconditional', $paidAs['status']);
    }

    public function test_salary_settlement_includes_volume_waived_dealer(): void
    {
        $this->enableFinancialPosting();
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        app(PartnerBenefitControls::class)->save($admin, $dealer, [
            'unconditional_salary' => true,
            'reason' => 'Waive salary volume for this partner',
        ]);

        $this->assertTrue(PartnerBenefitControls::waivesSalaryVolume($dealer->fresh()));
    }
}
