<?php

namespace Tests\Feature;

use App\Domain\Finance\ReportData;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/** The staff payroll download needs the Payroll permission, not only Reports. */
class PayrollReportAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->employee = User::factory()->staff('employee')->create(['status' => 'active']);
        $this->payrollRow($this->employee);
    }

    private function payrollRow(User $staff): void
    {
        $contractId = DB::table('staff_contracts')->insertGetId([
            'staff_id' => $staff->id, 'effective_month' => '2026-10', 'revision' => 1, 'base_salary' => 100, 'allowance' => 0, 'deduction' => 0,
            'reason' => 'Test contract', 'created_by' => $staff->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('payroll_entries')->insert([
            'id' => (string) Str::uuid(), 'staff_id' => $staff->id, 'contract_id' => $contractId, 'period_month' => '2026-10', 'snapshot' => '{}',
            'gross' => 100, 'deduction' => 0, 'net' => 100, 'status' => 'approved', 'prepared_by' => $staff->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<string> */
    private function namesFor(User $viewer): array
    {
        return app(ReportData::class)->query($viewer, 'payroll', now()->subDay()->toDateString(), now()->addDay()->toDateString())['query']->pluck('name')->all();
    }

    public function test_reports_and_finance_without_payroll_are_refused_the_staff_payroll(): void
    {
        $noPayroll = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['reports', 'finance']]]);

        $this->assertNotContains('payroll', app(ReportData::class)->availableTo($noPayroll));
        $this->expectException(HttpException::class);
        $this->namesFor($noPayroll);
    }

    public function test_the_payroll_permission_opens_the_whole_file(): void
    {
        $allowed = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->assertContains('payroll', app(ReportData::class)->availableTo($allowed));
        $this->assertSame([$this->employee->name], $this->namesFor($allowed));
    }

    public function test_the_md_needs_the_payroll_permission_too(): void
    {
        $md = User::factory()->staff('md')->create(['status' => 'active']);

        $this->assertNotContains('payroll', app(ReportData::class)->availableTo($md));
        $this->expectException(HttpException::class);
        $this->namesFor($md);
    }

    public function test_an_ordinary_staff_member_still_gets_only_their_own_slips(): void
    {
        $ordinary = User::factory()->staff('employee')->create(['status' => 'active', 'profile' => ['permissions' => ['orders']]]);
        $this->payrollRow($ordinary);

        $this->assertContains('payroll', app(ReportData::class)->availableTo($ordinary));
        $this->assertSame([$ordinary->name], $this->namesFor($ordinary));
    }
}
