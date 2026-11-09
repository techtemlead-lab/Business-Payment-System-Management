<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class MyMoneyPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dealer_gets_everything_the_my_money_page_reads(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/my-money')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertMyMoneyProps($page));
    }

    public function test_a_sub_dealer_gets_everything_the_my_money_page_reads(): void
    {
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')
            ->get('/my-money')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $this->assertMyMoneyProps($page));
    }

    public function test_the_partner_home_carries_the_income_cards(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->loadDeferredProps(fn (AssertableInertia $reload) => $reload->has('data.money_cards')));
    }

    public function test_every_my_money_word_is_there_in_both_languages(): void
    {
        $bangla = require lang_path('bn/my_money.php');
        $english = require lang_path('en/my_money.php');

        $this->assertSame(array_keys($bangla), array_keys($english));
        $this->assertSame([], array_keys(array_filter($bangla, fn (string $word): bool => trim($word) === '')));
        $this->assertSame([], array_keys(array_filter($english, fn (string $word): bool => trim($word) === '')));
    }

    private function assertMyMoneyProps(AssertableInertia $page): AssertableInertia
    {
        return $page->component('MyMoney')
            ->has('summary.available')
            ->has('summary.waiting')
            ->has('summary.asked')
            ->has('summary.lifetime.ordered_mrp')
            ->has('summary.lifetime.cash_given')
            ->has('summary.lifetime.withdrawn')
            ->has('summary.lifetime.spent')
            ->has('cards')
            ->has('table.rows.data')
            ->has('table.state.filters')
            ->has('withdraw.can')
            ->has('withdraw.kyc_block')
            ->has('withdraw.minimum')
            ->has('certificates.years')
            ->has('certificates.rank');
    }
}
