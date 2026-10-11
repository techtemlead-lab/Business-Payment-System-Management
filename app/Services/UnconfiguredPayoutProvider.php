<?php

namespace App\Services;

use App\Contracts\PayoutProvider;

class UnconfiguredPayoutProvider implements PayoutProvider
{
    public function available(): bool
    {
        return false;
    }

    public function transfer(string $reference, int $amountMinor, string $beneficiary): array
    {
        throw new \LogicException('No approved disbursement provider configured.');
    }

    public function reconcile(string $reference): array
    {
        return ['status' => 'needs_reconciliation'];
    }

    public function verifyWebhook(string $payload, string $signature): bool
    {
        return false;
    }
}
