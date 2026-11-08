<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionService;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Owner decision: TDS stays 0% by default and is changed on the commission settings page. */
class PayoutTaxSettingPageTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $finance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
        $this->owner = User::factory()->staff('super_admin')->create();
        $this->finance = User::factory()->staff('finance')->create();
    }

    private function taxRate(): string
    {
        return (string) app(CommissionPolicy::class)->snapshot()['rules']['payout_tax_withholding']['percentage'];
    }

    private function saveTaxRate(User $actor, string $portal, string $percentage): TestResponse
    {
        return $this->actingAs($actor)->onPortal($portal)
            ->post('/commands/commissions/rules', ['rules' => ['payout_tax_withholding' => ['percentage' => $percentage]]]);
    }

    public function test_tds_is_zero_by_default_and_is_listed_on_the_settings_page(): void
    {
        $this->assertSame('0.00', $this->taxRate());
        $this->assertSame('0.00', app(CommissionService::class)->calculatePayoutTds(10000.00)['tds_amount']);

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/commissions')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('data.rules', fn ($rules): bool => (string) collect($rules)->firstWhere('rule_key', 'payout_tax_withholding')['percentage'] === '0.00'));
    }

    public function test_the_owner_changes_tds_and_it_is_used_and_audit_logged(): void
    {
        $this->saveTaxRate($this->owner, 'sadmin', '5.00')
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_commission_policy_updated'));

        $this->assertSame('5.00', $this->taxRate());
        $this->assertSame('500.00', app(CommissionService::class)->calculatePayoutTds(10000.00)['tds_amount']);
        $event = DB::table('audit_events')->where('action', 'commissions.rules_updated')->first();
        $changes = json_decode($event->metadata, true)['changes']['payout_tax_withholding'];
        $this->assertSame($this->owner->id, (int) $event->actor_id);
        $this->assertSame('0.00', (string) $changes['before']['percentage']);
        $this->assertSame('5.00', (string) $changes['after']['percentage']);
    }

    public function test_finance_staff_propose_a_tds_change_and_it_applies_once_the_owner_approves(): void
    {
        $this->saveTaxRate($this->finance, 'manage', '5.00')
            ->assertRedirect()
            ->assertSessionHas('success', __('portal.flash_commission_change_pending'));

        $this->assertSame('0.00', $this->taxRate());
        $this->assertDatabaseHas('audit_events', ['action' => 'commissions.rules_change_proposed', 'actor_id' => $this->finance->id]);

        $id = (int) DB::table('commission_policy_schedules')->where('origin', 'rule_edit')->orderByDesc('id')->value('id');
        $this->actingAs($this->owner)->postJson('/api/v1/operations/policy-schedules/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Checked with the accountant'])->assertOk();

        $this->assertSame('5.00', $this->taxRate());
    }

    public function test_people_without_finance_rights_cannot_change_tds(): void
    {
        $this->saveTaxRate(User::factory()->staff('inventory')->create(), 'manage', '5.00')->assertForbidden();

        $this->assertSame('0.00', $this->taxRate());
        $this->assertDatabaseMissing('audit_events', ['action' => 'commissions.rules_change_proposed']);
    }
}
