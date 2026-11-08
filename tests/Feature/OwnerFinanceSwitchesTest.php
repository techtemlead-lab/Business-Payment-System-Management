<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The Finance settings page shows these two switches to every owner (Super Admin, MD, Chairman)
 * and says they may change them. The staff-actions gate must not turn the MD or Chairman away.
 */
class OwnerFinanceSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['managing director' => ['md'], 'chairman' => ['chairman']];
    }

    #[DataProvider('owners')]
    public function test_an_owner_can_switch_full_payment_when_ordering(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Trusted dealers may pay after ordering',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']);
    }

    #[DataProvider('owners')]
    public function test_an_owner_can_switch_the_id_check_before_the_first_withdrawal(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Check identity before the first payout',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);
    }

    public function test_finance_staff_still_cannot_change_these_owner_switches(): void
    {
        $finance = User::factory()->staff('finance')->create();

        $status = $this->actingAs($finance)->onPortal('manage')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Trying to change an owner switch',
        ])->getStatusCode();

        $this->assertContains($status, [403, 404]);
        $this->assertFalse(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);
    }
}
