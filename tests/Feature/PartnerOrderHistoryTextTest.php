<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerOrderHistoryTextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function orderWithCourierSkip(User $partner, string $reason): Order
    {
        $order = Order::create([
            'user_id' => $partner->id,
            'number' => 'HIS-'.Str::random(6),
            'status' => 'approved',
            'total' => '100.00',
            'paid' => '100.00',
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::uuid()),
        ]);
        DB::table('order_events')->insert([
            'order_id' => $order->id,
            'action' => 'order.courier_auto_skipped',
            'metadata' => json_encode(['provider' => 'sundarban', 'reason' => $reason]),
            'created_at' => now(),
        ]);

        return $order;
    }

    public function test_a_partner_does_not_get_the_internal_reason_a_courier_was_not_booked(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $order = $this->orderWithCourierSkip($dealer, 'manual_courier');

        $this->actingAs($dealer)->onPortal('dealer')->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderDetail')
                ->where('events.0.action', 'order.courier_auto_skipped')
                ->where('events.0.metadata', fn ($metadata): bool => ! str_contains((string) json_encode($metadata), 'manual_courier')));
    }

    public function test_the_office_still_gets_the_reason_a_courier_was_not_booked(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $order = $this->orderWithCourierSkip($dealer, 'stock');

        $this->actingAs($admin)->onPortal('sadmin')->get('/orders/'.$order->id)
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('OrderDetail')
                ->where('events.0.metadata', fn ($metadata): bool => str_contains((string) json_encode($metadata), 'stock')));
    }

    /** @return list<array{0: string, 1: string}> */
    public static function courierSkipReasons(): array
    {
        $rows = [];
        foreach (['manual_courier', 'courier_off', 'no_branch', 'stock'] as $reason) {
            foreach (['en', 'bn'] as $locale) {
                $rows[] = [$reason, $locale];
            }
        }

        return $rows;
    }

    #[DataProvider('courierSkipReasons')]
    public function test_every_courier_skip_reason_has_words_for_the_office(string $reason, string $locale): void
    {
        $key = 'portal.courier_skip_reason_'.$reason;

        $this->assertNotSame($key, __($key, [], $locale));
    }

    public function test_the_payment_verified_sms_does_not_repeat_a_word_in_bangla(): void
    {
        $message = __('sms.payment_reviewed', ['number' => 'MN-1', 'outcome' => __('portal.payment_outcome_verified', [], 'bn')], 'bn');

        $this->assertStringNotContainsString('হয়েছে হয়েছে', $message);
        $this->assertStringContainsString('যাচাই হয়েছে', $message);
    }
}
