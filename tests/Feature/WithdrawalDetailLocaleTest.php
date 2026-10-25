<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\PlaceCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WithdrawalDetailLocaleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(PlaceCodeSeeder::class);
        config(['madina.financial_activation' => false]);
    }

    public function test_withdrawal_detail_exposes_bangla_chrome_keys(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $id = (string) Str::uuid();
        DB::table('wallets')->insert([
            'user_id' => $dealer->id,
            'available' => '0.00',
            'held' => '200.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('withdrawals')->insert([
            'id' => $id,
            'user_id' => $dealer->id,
            'amount' => '200.00',
            'status' => 'requested',
            'channel' => 'bKash',
            'destination' => encrypt('01700000000'),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now()->subDay(),
            'updated_at' => now(),
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->post('/locale', ['locale' => 'bn'])->assertRedirect();

        $this->actingAs($dealer)->onPortal('dealer')->get('/withdrawals/'.$id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('WithdrawalDetail')
                ->where('translations.portal.wd_head_title', 'উত্তোলনের বিবরণ')
                ->where('translations.portal.wd_manual_title', 'ম্যানুয়াল পেমেন্ট বিবরণ')
                ->where('translations.portal.wd_history_title', 'অনুরোধ ইতিহাস'));
    }
}
