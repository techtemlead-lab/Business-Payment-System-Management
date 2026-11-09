<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionWallet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The account book's "By" column names the person who did the work. */
class LedgerEntryAuthorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_journal_entry_records_who_made_it_and_the_ledger_shows_them(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'name' => 'The Owner']);
        $this->actingAs($owner);

        DB::transaction(fn () => app(CommissionWallet::class)->journal('test.entry.1', 'Test entry', [
            ['account' => 'company_fund_expense', 'user_id' => null, 'debit' => 1000, 'credit' => 0],
            ['account' => 'manual_payout_clearing', 'user_id' => null, 'debit' => 0, 'credit' => 1000],
        ]));

        $this->assertDatabaseHas('journal_entries', ['event_key' => 'test.entry.1', 'actor_id' => $owner->id]);
        $this->onPortal('sadmin')->get('/ledger')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('data.entries.data.0.actor_name', 'The Owner')
            ->etc());
    }

    public function test_the_additional_rank_reward_has_a_name_in_both_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $this->assertNotSame('portal.reward_program_additional_rank', __('portal.reward_program_additional_rank', [], $locale));
        }
    }
}
