<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Owner's decision: a transaction reference must be at least 6 characters, everywhere one is
 * entered, and Bangla digits are accepted.
 */
class TransactionReferenceMinimumTest extends TestCase
{
    use RefreshDatabase;

    private function submittedOrder(User $buyer): Order
    {
        return Order::create(['user_id' => $buyer->id, 'number' => 'REF-'.Str::random(6), 'total' => '1000.00', 'paid' => '0.00', 'status' => 'submitted',
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', 'order')]);
    }

    private function claim(User $buyer, Order $order, string $reference): TestResponse
    {
        return $this->actingAs($buyer)->onPortal('dealer')->from('/orders/'.$order->id)->post('/commands/orders/'.$order->id.'/payments', [
            'channel' => 'Bank', 'reference' => $reference, 'amount' => '1000.00', 'proof' => UploadedFile::fake()->image('proof.jpg'),
        ]);
    }

    public function test_a_partner_payment_needs_a_reference_of_six_characters(): void
    {
        Storage::fake('local');
        $buyer = User::factory()->dealer()->create();
        $order = $this->submittedOrder($buyer);

        $this->claim($buyer, $order, 'AB12')->assertSessionHasErrors(['reference' => __('portal.err_reference_min', ['min' => 6])]);

        $this->assertDatabaseCount('payment_claims', 0);
    }

    public function test_bangla_digits_are_accepted_and_stored_as_ordinary_digits(): void
    {
        Storage::fake('local');
        $buyer = User::factory()->dealer()->create();
        $order = $this->submittedOrder($buyer);

        $this->claim($buyer, $order, '১২৩৪৫৬')->assertSessionDoesntHaveErrors('reference');

        $this->assertDatabaseHas('payment_claims', ['order_id' => $order->id, 'reference' => '123456']);
    }

    public function test_a_reference_of_bangla_digits_still_has_to_be_six_long(): void
    {
        Storage::fake('local');
        $buyer = User::factory()->dealer()->create();
        $order = $this->submittedOrder($buyer);

        $this->claim($buyer, $order, '১২৩৪৫')->assertSessionHasErrors(['reference' => __('portal.err_reference_min', ['min' => 6])]);
    }

    public function test_a_staff_payout_needs_a_reference_of_six_characters(): void
    {
        $finance = User::factory()->create(['role' => 'finance']);
        $response = $this->actingAs($finance)->postJson('/commands/withdrawals/'.Str::uuid().'/record-payment', ['method' => 'bank', 'payment_reference' => 'EFT-1']);

        $response->assertUnprocessable()->assertJsonValidationErrors(['payment_reference' => __('portal.err_reference_min', ['min' => 6])]);
    }

    public function test_a_fund_spend_needs_a_reference_of_six_characters(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $head = DB::table('fund_expense_heads')->insertGetId(['name_en' => 'Test head', 'name_bn' => 'Test head', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/company-funds/spends', [
            'fund' => 'marketing', 'expense_head_id' => $head, 'amount' => '10.00', 'spent_on' => now('Asia/Dhaka')->toDateString(),
            'payee' => 'Fake payee', 'payment_method' => 'bank', 'reference' => 'ABC', 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference' => __('portal.err_reference_min', ['min' => 6])]);
    }

    public function test_a_cash_fund_spend_still_needs_no_reference(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        $head = DB::table('fund_expense_heads')->insertGetId(['name_en' => 'Test head', 'name_bn' => 'Test head', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner)->onPortal('sadmin')->postJson('/commands/operations/company-funds/spends', [
            'fund' => 'marketing', 'expense_head_id' => $head, 'amount' => '10.00', 'spent_on' => now('Asia/Dhaka')->toDateString(),
            'payee' => 'Fake payee', 'payment_method' => 'cash', 'request_key' => (string) Str::uuid(),
        ])->assertJsonMissingValidationErrors('reference');
    }

    public function test_the_message_says_the_same_in_both_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertNotSame('portal.err_reference_min', __('portal.err_reference_min', ['min' => 6], $locale));
        }
    }
}
