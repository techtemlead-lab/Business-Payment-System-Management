<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** A company wallet number must be a real mobile number, or partners send money to nowhere. */
class PaymentAccountNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.financial_activation' => true]);
    }

    public function test_a_short_mobile_wallet_number_is_refused_with_the_reason(): void
    {
        $this->save(['channel' => 'bKash', 'account_number' => '12'])->assertUnprocessable()
            ->assertJsonValidationErrors(['account_number' => __('portal.err_pay_acct_number')]);

        $this->assertDatabaseCount('payment_accounts', 0);
    }

    public function test_full_numbers_save_for_each_wallet_including_bangla_digits(): void
    {
        $this->save(['channel' => 'bKash', 'account_number' => '01712345678'])->assertOk();
        $this->save(['channel' => 'Nagad', 'account_number' => '০১৮১২৩৪৫৬৭৮'])->assertOk();
        $this->save(['channel' => 'Rocket', 'account_number' => '017123456789'])->assertOk();

        $this->assertSame('01812345678', decrypt(DB::table('payment_accounts')->where('channel', 'Nagad')->value('account_number')));
    }

    public function test_bank_account_numbers_are_not_limited_to_mobile_format(): void
    {
        $this->save(['channel' => 'Bank', 'account_number' => '2050-123-45'])->assertOk();
    }

    private function save(array $overrides)
    {
        return $this->actingAs(User::factory()->staff('finance')->create())->postJson('/api/v1/operations/payment-accounts', [
            'label' => 'Company account', 'channel' => 'bKash', 'holder' => 'Madina Natural Health Care', 'account_number' => '01712345678',
            'details' => '', 'collects_payments' => true, 'pays_out' => false, 'is_active' => true, 'reason' => 'Adding the company account',
            ...$overrides,
        ]);
    }
}
