<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerBenefitControls;
use App\Domain\Finance\PaymentAccounts;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceAccountsLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_payout_account_returns_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            app(PaymentAccounts::class)->assertPayout(999999);
            $this->fail('Expected ValidationException for missing payout account');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_pay_acct_payout', [], 'bn'),
                $exception->errors()['payment_account_id'][0] ?? null,
            );
        }
    }

    public function test_benefit_controls_reject_staff_with_bangla_message(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'editor', 'status' => 'active']);

        try {
            app(PartnerBenefitControls::class)->save($actor, $staff, [
                'unconditional_salary' => true,
                'forced_royalty_rank' => 'mo',
            ]);
            $this->fail('Expected ValidationException for staff benefit controls');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_benefit_partners_only', [], 'bn'),
                $exception->errors()['user_id'][0] ?? null,
            );
        }
    }
}
