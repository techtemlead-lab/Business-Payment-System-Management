<?php

namespace App\Domain\Finance;

use App\Models\User;
use App\Support\Audit;
use App\Support\Money;
use Illuminate\Support\Facades\DB;

final class FraudAnomalyDetector
{
    public const RiskLow = 'low';

    public const RiskMedium = 'medium';

    public const RiskHigh = 'high';

    public const ThresholdHighVelocity = 3;

    public const ThresholdLargeSinglePayout = 100000.00; // 100k BDT triggers dual review

    /**
     * Analyze a withdrawal request for financial fraud, velocity bursts, or anomalies.
     *
     * @return array{score: int, risk_level: string, flags: list<string>, requires_dual_approval: bool}
     */
    public function analyzeWithdrawal(User $partner, float $amount): array
    {
        $flags = [];
        $score = 0;

        // 1. Check 24-hour withdrawal velocity
        $recentWithdrawals = DB::table('withdrawals')
            ->where('user_id', $partner->id)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        if ($recentWithdrawals >= self::ThresholdHighVelocity) {
            $flags[] = 'VELOCITY_BURST_24H';
            $score += 35;
        }

        // 2. Check large single payout threshold
        if ($amount >= self::ThresholdLargeSinglePayout) {
            $flags[] = 'LARGE_PAYOUT_THRESHOLD';
            $score += 30;
        }

        // 3. Check rapid succession (another withdrawal within past 30 minutes)
        $veryRecent = DB::table('withdrawals')
            ->where('user_id', $partner->id)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->exists();

        if ($veryRecent) {
            $flags[] = 'RAPID_SUCCESSION_PAYOUT';
            $score += 25;
        }

        // 4. Check new account with high withdrawal (account created < 7 days ago)
        if ($partner->created_at && $partner->created_at->gt(now()->subDays(7)) && $amount > 10000.00) {
            $flags[] = 'NEW_ACCOUNT_LARGE_WITHDRAWAL';
            $score += 20;
        }

        $riskLevel = match (true) {
            $score >= 50 => self::RiskHigh,
            $score >= 25 => self::RiskMedium,
            default => self::RiskLow,
        };

        $requiresDualApproval = ($amount >= self::ThresholdLargeSinglePayout) || ($score >= 50);

        if ($score >= 25) {
            Audit::record(
                $partner->id,
                'risk.anomaly_detected',
                'user:'.$partner->id,
                [
                    'amount' => $amount,
                    'score' => $score,
                    'risk_level' => $riskLevel,
                    'flags' => $flags,
                ]
            );
        }

        return [
            'score' => $score,
            'risk_level' => $riskLevel,
            'flags' => $flags,
            'requires_dual_approval' => $requiresDualApproval,
        ];
    }

    /**
     * Inspect a payment claim reference for duplicate collision or circular settlement.
     *
     * @return array{is_suspicious: bool, flag: ?string}
     */
    public function analyzePaymentClaim(string $channel, string $reference, ?string $excludeOrderId = null): array
    {
        $cleanRef = strtoupper(trim($reference));

        // Check if reference exists in existing verified receipts
        $receiptQuery = DB::table('receipts')
            ->where('channel', $channel)
            ->where('reference', $cleanRef);

        if ($excludeOrderId) {
            $receiptQuery->where('order_id', '!=', $excludeOrderId);
        }

        if ($receiptQuery->exists()) {
            return [
                'is_suspicious' => true,
                'flag' => 'DUPLICATE_RECEIPT_REFERENCE',
            ];
        }

        // Check if multiple pending claims share the same reference
        $pendingClaims = DB::table('payment_claims')
            ->where('reference', $cleanRef)
            ->count();

        if ($pendingClaims > 1) {
            return [
                'is_suspicious' => true,
                'flag' => 'MULTIPLE_PENDING_CLAIMS_FOR_REFERENCE',
            ];
        }

        return [
            'is_suspicious' => false,
            'flag' => null,
        ];
    }
}
