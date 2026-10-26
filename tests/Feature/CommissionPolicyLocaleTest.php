<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommissionPolicyLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_commission_rule_keys_return_bangla_validation(): void
    {
        app()->setLocale('bn');

        try {
            app(CommissionPolicy::class)->compose(app(CommissionPolicy::class)->snapshot(false)['rules'], [
                'not_a_real_rule' => ['percentage' => '1.00'],
            ]);
            $this->fail('Expected ValidationException for unknown commission rule keys');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_policy_unknown_keys', [], 'bn'),
                $exception->errors()['rules'][0] ?? null,
            );
        }
    }

    public function test_over_allocated_dealer_pool_returns_bangla_validation(): void
    {
        app()->setLocale('bn');
        $rules = app(CommissionPolicy::class)->snapshot(false)['rules'];
        $rules['own_dealer_cashback']['percentage'] = '40.00';
        $rules['own_dealer_cashback']['is_active'] = true;

        try {
            app(CommissionPolicy::class)->validateSchedule($rules);
            $this->fail('Expected ValidationException for over-allocated dealer pool');
        } catch (ValidationException $exception) {
            $this->assertSame(
                __('portal.err_policy_pool_limits', [], 'bn'),
                $exception->errors()['rules'][0] ?? null,
            );
        }
    }

    public function test_policy_snapshot_localizes_rule_names_for_studio_locale(): void
    {
        app()->setLocale('bn');
        $name = app(CommissionPolicy::class)->snapshot(false)['rules']['direct_referral']['name'] ?? null;
        $this->assertSame(__('portal.rule_name_direct_referral', [], 'bn'), $name);

        app()->setLocale('en');
        $english = app(CommissionPolicy::class)->snapshot(false)['rules']['direct_referral']['name'] ?? null;
        $this->assertSame(__('portal.rule_name_direct_referral', [], 'en'), $english);
    }
}
