<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Domain\Finance\CalculationMessage;
use App\Support\SafeSiteUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class RemainingDomainLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_wallet_posting_disabled_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        config(['madina.financial_activation' => false, 'madina.preview_mode' => false]);

        try {
            CommissionWallet::requireActive();
            $this->fail('Expected ValidationException when financial posting is disabled');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_wallet_posting_disabled', [], 'bn'),
                $exception->errors()['policy'][0] ?? null,
            );
        }
    }

    public function test_unsafe_site_url_returns_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            SafeSiteUrl::clean(['hero_url' => 'javascript:alert(1)'], validate: true);
            $this->fail('Expected ValidationException for unsafe site URL');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_safe_url', [], 'bn'),
                $exception->errors()['customization.hero_url'][0] ?? null,
            );
        }
    }

    public function test_calculation_message_localizes_percentage_errors(): void
    {
        app()->setLocale('bn');

        $this->assertSame(
            __('portal.err_pct_decimals', [], 'bn'),
            CalculationMessage::localize('A percentage must have at most two decimal places.'),
        );
    }
}
