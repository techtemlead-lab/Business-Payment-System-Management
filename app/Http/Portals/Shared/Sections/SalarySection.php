<?php

namespace App\Http\Portals\Shared\Sections;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Commissions\CommissionQualification;
use App\Models\CommissionAllocation;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

/**
 * Sub-dealer salary and tour fund for the current month: company-wide for staff,
 * the partner's own otherwise.
 */
final class SalarySection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        if ($context->section !== 'salary') {
            return [];
        }
        $user = $context->user;
        $props = [];
        $month = CommissionQualification::currentMonth();
        $rules = app(CommissionPolicy::class)->snapshot()['rules'];
        if ($context->isStaff) {
            $pool = CommissionAllocation::where('category', 'sub_dealer_salary')->where('metadata->period_month', $month);
            $tour = CommissionAllocation::where('category', 'sub_dealer_tour')->where('metadata->period_month', $month);
            $props['salary'] = [
                'month' => $month,
                'staff_view' => true,
                'monthly_purchases' => Money::decimal(Money::cents((string) DB::table('commission_batches')
                    ->where('account_type', 'sub_dealer')->where('period_month', $month)->where('no_commission', false)->sum('cash_basis'))),
                'salary_rate' => $rules['sub_dealer_salary']['percentage'],
                'tour_rate' => $rules['sub_dealer_tour']['percentage'],
                'salary_accrued' => (string) (clone $pool)->sum('amount'),
                'salary_pending' => (string) (clone $pool)->where('status', 'pending')->sum('amount'),
                'tour_reserved' => (string) $tour->sum('amount'),
            ];
        } else {
            $props['salary'] = ['month' => $month, 'staff_view' => false, 'monthly_purchases' => Money::decimal(app(CommissionQualification::class)->monthlyVolume($user->id, $month)),
                'salary_rate' => $rules['sub_dealer_salary']['percentage'], 'tour_rate' => $rules['sub_dealer_tour']['percentage'],
                'salary_accrued' => (string) CommissionAllocation::where('user_id', $user->id)->where('category', 'sub_dealer_salary')->where('metadata->period_month', $month)->sum('amount'),
                'tour_reserved' => (string) CommissionAllocation::where('user_id', $user->id)->where('category', 'sub_dealer_tour')->where('metadata->period_month', $month)->sum('amount')];
        }

        return $props;
    }
}
