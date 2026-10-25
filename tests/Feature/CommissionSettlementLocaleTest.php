<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionSettlement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommissionSettlementLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_month_settlement_returns_bangla_validation(): void
    {
        $this->enableFinancialPosting();
        app()->setLocale('bn');

        try {
            app(CommissionSettlement::class)->settle(now('Asia/Dhaka')->format('Y-m'), 'salary');
            $this->fail('Expected ValidationException for open settlement month');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'স্যালারি বা রয়্যালটি সেটেল করার আগে ক্যালেন্ডার মাস শেষ করুন।',
                $exception->errors()['period_month'][0] ?? null,
            );
        }
    }
}
