<?php

namespace Tests\Feature;

use App\Domain\Finance\FinanceSettings;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * These two switches belong to the Super Admin alone: the MD and the Chairman can see where they
 * stand but are refused on the server and get no controls.
 */
class OwnerFinanceSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.full_payment_when_ordering' => true]);
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['managing director' => ['md'], 'chairman' => ['chairman']];
    }

    #[DataProvider('owners')]
    public function test_md_and_chairman_are_refused_full_payment_when_ordering(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Trusted dealers may pay after ordering',
        ])->assertForbidden();

        $this->assertTrue(app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'finance.full_payment_when_ordering_changed']);
    }

    #[DataProvider('owners')]
    public function test_md_and_chairman_are_refused_the_id_check_before_the_first_withdrawal(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Check identity before the first payout',
        ])->assertForbidden();

        $this->assertFalse(app(FinanceSettings::class)->kycBeforeFirstWithdrawal()['enabled']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'finance.kyc_before_first_withdrawal_changed']);
    }

    #[DataProvider('owners')]
    public function test_md_and_chairman_are_refused_by_the_settings_service_itself(string $role): void
    {
        $owner = User::factory()->staff($role)->create();
        $settings = app(FinanceSettings::class);

        $this->expectException(HttpException::class);

        $settings->saveFullPaymentWhenOrdering($owner, false, 0, 'Going around the page');
    }

    #[DataProvider('owners')]
    public function test_md_and_chairman_see_where_the_rules_stand_with_no_controls(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/operations/finance-settings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('fullPaymentWhenOrdering.enabled', true)
                ->where('fullPaymentWhenOrdering.can_edit', false)
                ->where('kycBeforeFirstWithdrawal.can_edit', false));
    }

    public function test_the_super_admin_can_still_change_both_rules(): void
    {
        $superAdmin = User::factory()->staff('super_admin')->create();

        $this->actingAs($superAdmin)->onPortal('sadmin')->post('/commands/operations/settings/full-payment-when-ordering', [
            'enabled' => false, 'version' => 0, 'reason' => 'Trusted dealers may pay after ordering',
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->actingAs($superAdmin)->onPortal('sadmin')->post('/commands/operations/settings/kyc-before-first-withdrawal', [
            'enabled' => true, 'version' => 0, 'reason' => 'Check identity before the first payout',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(app(FinanceSettings::class)->fullPaymentWhenOrdering()['enabled']);
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
