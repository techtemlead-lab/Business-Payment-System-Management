<?php

namespace Tests\Feature;

use App\Domain\Finance\PayrollService;
use App\Domain\Finance\ReserveService;
use App\Domain\Staff\StaffOperations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StaffReserveLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_payroll_month_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->staff('finance')->create();

        try {
            app(PayrollService::class)->prepare($actor, now('Asia/Dhaka')->format('Y-m'));
            $this->fail('Expected ValidationException for open payroll month');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_payroll_month_open', [], 'bn'),
                $exception->errors()['period_month'][0] ?? null,
            );
        }
    }

    public function test_tour_reserve_for_dealer_returns_bangla_validation(): void
    {
        $this->enableFinancialPosting();
        app()->setLocale('bn');
        $actor = User::factory()->staff('finance')->create();
        $dealer = User::factory()->dealer()->create();

        try {
            app(ReserveService::class)->request($actor, [
                'program' => 'tour',
                'user_id' => $dealer->id,
                'amount' => '100.00',
                'request_key' => (string) Str::uuid(),
                'reason' => 'Locale tour reserve check',
                'payee_name' => null,
            ]);
            $this->fail('Expected ValidationException for tour reserve on dealer');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_reserve_tour_subdealer', [], 'bn'),
                $exception->errors()['user_id'][0] ?? null,
            );
        }
    }

    public function test_staff_target_without_territory_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $sr = User::factory()->staff('sr')->create();

        try {
            app(StaffOperations::class)->target($actor, [
                'staff_id' => $sr->id,
                'period_month' => now('Asia/Dhaka')->format('Y-m'),
                'target' => '10000.00',
                'bonus' => '500.00',
                'version' => 0,
            ]);
            $this->fail('Expected ValidationException when SR has no territory');
        } catch (ValidationException $exception) {
            $this->assertContains(
                $exception->errors()['staff_id'][0] ?? null,
                [
                    __('portal.err_staff_target_territory', [], 'bn'),
                    __('portal.err_staff_target_invalid', [], 'bn'),
                ],
            );
        }
    }
}
