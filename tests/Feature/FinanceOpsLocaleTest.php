<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionQualification;
use App\Domain\Commissions\PolicyScheduling;
use App\Domain\Finance\FinancialReadiness;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceOpsLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_invalid_qualification_month_returns_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            CommissionQualification::period('not-a-month');
            $this->fail('Expected ValidationException for invalid qualification month');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_qual_month', [], 'bn'),
                $exception->errors()['period_month'][0] ?? null,
            );
        }
    }

    public function test_backdated_policy_schedule_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->staff('finance')->create();

        try {
            app(PolicyScheduling::class)->propose($actor, [
                'scope' => 'global',
                'user_id' => null,
                'rules' => ['own_dealer_cashback' => ['percentage' => '5.00']],
                'effective_at' => now()->subDays(2)->toIso8601String(),
                'expires_at' => null,
                'reason' => 'Locale backdate check',
            ]);
            $this->fail('Expected ValidationException for backdated policy schedule');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_sched_backdated', [], 'bn'),
                $exception->errors()['effective_at'][0] ?? null,
            );
        }
    }

    public function test_opening_verify_self_review_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $actor = User::factory()->staff('finance')->create();
        $id = DB::table('financial_verifications')->insertGetId([
            'fingerprint' => 'locale-test',
            'snapshot' => json_encode(['totals' => []], JSON_THROW_ON_ERROR),
            'expected_totals' => json_encode([], JSON_THROW_ON_ERROR),
            'source_reference' => 'Locale',
            'evidence_path' => 'opening-balance-evidence/x.bin',
            'evidence_hash' => str_repeat('a', 64),
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(FinancialReadiness::class)->verify($actor, $id);
            $this->fail('Expected ValidationException for self opening verification');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_ready_separate_verify', [], 'bn'),
                $exception->errors()['balances'][0] ?? null,
            );
        }
    }
}
