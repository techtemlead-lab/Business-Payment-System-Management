<?php

namespace App\Contracts;

interface PayoutProvider
{
    public function available(): bool;

    public function transfer(string $reference, int $amountMinor, string $beneficiary): array;

    public function reconcile(string $reference): array;

    public function verifyWebhook(string $payload, string $signature): bool;
}
