<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Finance\DistributionCalculator;
use App\Domain\Finance\DistributionSchedule;
use App\Domain\Finance\SalaryCalculator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Backend\PreviewDistributionRequest;
use App\Http\Requests\Backend\PreviewSalaryRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class FinancialPreviewController extends Controller
{
    public function defaults(Request $request): JsonResponse
    {
        abort_unless($request->user()->allows('finance'), 403);

        return response()->json(['data' => [
            'basis' => 'purchase_value', 'currency' => 'BDT',
            'dealer' => DistributionSchedule::defaults('dealer'), 'sub_dealer' => DistributionSchedule::defaults('sub_dealer'),
            'sub_dealer_salary_rate' => '3.00', 'sub_dealer_tour_rate' => '3.00',
            'discount_range' => ['minimum' => '20.00', 'maximum' => '50.00'],
            'default_rounding' => 'reject', 'posts_earnings' => false,
        ]]);
    }

    public function distribution(PreviewDistributionRequest $request, DistributionCalculator $calculator): JsonResponse
    {
        $data = $request->validated();
        try {
            $result = $calculator->calculate($data['mrp'], $data['account_type'], $data['discount_percent'],
                $data['components'] ?? [], $data['company_share_percent'] ?? '30.00', $data['rounding'] ?? 'reject');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculation' => $exception->getMessage()]);
        }

        return response()->json(['data' => $result]);
    }

    public function salary(PreviewSalaryRequest $request, SalaryCalculator $calculator): JsonResponse
    {
        $data = $request->validated();
        try {
            $result = $calculator->calculate($data['monthly_purchases'], $data['rounding'] ?? 'reject');
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['calculation' => $exception->getMessage()]);
        }

        return response()->json(['data' => $result]);
    }
}
