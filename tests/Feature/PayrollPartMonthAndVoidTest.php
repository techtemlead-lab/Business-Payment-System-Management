<?php

namespace Tests\Feature;

use App\Domain\Finance\PayrollActiveDays;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PayrollPartMonthAndVoidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->setDate(2026, 10, 17)->startOfDay());
    }

    private function contract(User $finance, User $staff, string $base = '30000', string $deduction = '3000'): void
    {
        $this->actingAs($finance)->postJson('/api/v1/operations/payroll/contracts', ['staff_id' => $staff->id, 'effective_month' => '2026-09', 'base_salary' => $base, 'allowance' => '0', 'deduction' => $deduction, 'reason' => 'Approved employment terms'])->assertOk();
    }

    /** Writes the same activity-log line the account page writes when the status changes. */
    private function suspend(User $staff, string $when): void
    {
        $this->travelTo($when);
        $staff->forceFill(['status' => 'suspended'])->save();
        Audit::record(null, 'account.updated', (string) $staff->id, ['fields' => ['status'], 'changes' => ['status' => ['before' => 'active', 'after' => 'suspended']], 'reason' => 'Left the company']);
        $this->travelTo('2026-10-17 00:00:00');
    }

    public function test_staff_suspended_during_or_after_the_month_get_pay_for_active_days_marked_for_review(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $midMonth = User::factory()->create(['role' => 'sr']);
        $afterMonth = User::factory()->create(['role' => 'sr']);
        $beforeMonth = User::factory()->create(['role' => 'sr']);
        $unknown = User::factory()->create(['role' => 'sr']);
        foreach ([$midMonth, $afterMonth, $beforeMonth, $unknown] as $staff) {
            $this->contract($finance, $staff);
        }
        // Dhaka time: 10 September 18:00 UTC is 11 September 00:00 Dhaka, so 11 of 30 days.
        $this->suspend($midMonth, '2026-09-10 18:00:00');
        $this->suspend($afterMonth, '2026-10-03 06:00:00');
        $this->suspend($beforeMonth, '2026-08-20 06:00:00');
        $unknown->forceFill(['status' => 'suspended'])->save();

        $ids = $this->actingAs($finance)->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids');
        $this->assertCount(2, $ids);
        $this->assertDatabaseHas('payroll_entries', ['staff_id' => $midMonth->id, 'gross' => '11000.00', 'deduction' => '1100.00', 'net' => '9900.00']);
        $this->assertDatabaseHas('payroll_entries', ['staff_id' => $afterMonth->id, 'gross' => '30000.00', 'deduction' => '3000.00', 'net' => '27000.00']);
        $this->assertDatabaseMissing('payroll_entries', ['staff_id' => $beforeMonth->id]);
        $this->assertDatabaseMissing('payroll_entries', ['staff_id' => $unknown->id]);

        $snapshot = json_decode((string) DB::table('payroll_entries')->where('staff_id', $midMonth->id)->value('snapshot'), true);
        $this->assertSame(11, $snapshot['part_month']['active_days']);
        $this->assertSame(30, $snapshot['part_month']['days_in_month']);
        $this->assertTrue($snapshot['part_month']['needs_review']);
        $midMonthEntry = DB::table('payroll_entries')->where('staff_id', $midMonth->id)->value('id');
        $this->assertStringContainsString('11', (string) DB::table('payroll_events')->where('payroll_id', $midMonthEntry)->where('action', 'prepared')->value('reason'));

        // Preparing again changes nothing.
        $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->assertJsonCount(2, 'data.ids');
        $this->assertSame(2, DB::table('payroll_entries')->count());
    }

    public function test_share_rounds_half_up_to_the_paisa(): void
    {
        $this->assertSame(1000000, PayrollActiveDays::share(3000000, 10, 30));
        $this->assertSame(32, PayrollActiveDays::share(100, 10, 31));
        $this->assertSame(100, PayrollActiveDays::share(100, 31, 31));
    }

    public function test_an_approved_unpaid_salary_is_voided_by_a_second_person_with_a_reversing_journal(): void
    {
        $preparer = User::factory()->create(['role' => 'finance']);
        $approver = User::factory()->create(['role' => 'finance']);
        $other = User::factory()->create(['role' => 'finance']);
        $staff = User::factory()->create(['role' => 'sr']);
        $this->contract($preparer, $staff, '20000', '500');
        $id = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->enableFinancialPosting();
        $this->actingAs($approver)->postJson('/api/v1/operations/payroll/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Salary reviewed independently'])->assertOk();

        $this->actingAs($staff)->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Approved twice by mistake'])->assertForbidden();
        $this->actingAs($approver)->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Approved twice by mistake'])->assertUnprocessable();
        $this->actingAs($other)->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'No'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Approved twice by mistake'])->assertOk();
        $this->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Approved twice by mistake'])->assertUnprocessable();

        $this->assertDatabaseHas('payroll_entries', ['id' => $id, 'status' => 'voided']);
        $this->assertSame(1, DB::table('journal_entries')->where('event_key', 'payroll.voided.'.$id)->count());
        foreach (['payroll_payable', 'payroll_deductions_payable', 'payroll_expense'] as $account) {
            $net = DB::table('journal_lines')->where('account', $account)->selectRaw('COALESCE(SUM(debit - credit), 0) AS net')->value('net');
            $this->assertEquals(0, $net, $account.' is back to zero');
        }
        $this->assertEquals(DB::table('journal_lines')->sum('debit'), DB::table('journal_lines')->sum('credit'));
        $this->assertDatabaseHas('audit_events', ['action' => 'payroll.voided', 'subject' => $id]);

        // The month is free again for a corrected entry.
        $again = $this->actingAs($preparer)->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->assertNotSame($id, $again);
    }

    public function test_paid_and_draft_salaries_cannot_be_voided_and_the_owner_voids_their_own_approval(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $staff = User::factory()->create(['role' => 'sr']);
        $this->contract($owner, $staff);
        $id = $this->postJson('/api/v1/operations/payroll/prepare', ['period_month' => '2026-09'])->assertOk()->json('data.ids.0');
        $this->enableFinancialPosting();
        $this->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Not approved yet'])->assertUnprocessable();
        $this->actingAs($owner)->postJson('/api/v1/operations/payroll/'.$id.'/review', ['decision' => 'approved', 'reason' => 'Owner checked it'])->assertOk();
        $this->actingAs($owner)->postJson('/api/v1/operations/payroll/'.$id.'/void', ['reason' => 'Owner made a mistake'])->assertOk();
        $this->assertDatabaseHas('payroll_entries', ['id' => $id, 'status' => 'voided']);
    }
}
