<?php

namespace Tests\Feature;

use App\Domain\Accounts\DuplicateIdentityScan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Accounts that look like one person are listed for the owner and finance. The check only
 * flags: it changes nothing on the accounts, and never shows a full NID or payout number.
 */
class DuplicateIdentityReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_partners_with_the_same_nid_are_flagged(): void
    {
        $first = User::factory()->dealer()->create(['nid' => '1234567890']);
        $second = User::factory()->dealer()->create(['nid' => '12345 67890']);
        User::factory()->dealer()->create(['nid' => '9999999999']);

        $this->scan();

        $flag = DB::table('identity_review_flags')->where('kind', 'nid')->sole();
        $this->assertSame([$first->id, $second->id], json_decode((string) $flag->user_ids, true));
        $this->assertSame('open', $flag->status);
    }

    public function test_the_nid_match_key_depends_on_the_app_key(): void
    {
        User::factory()->dealer()->create(['nid' => '1234567890']);
        User::factory()->dealer()->create(['nid' => '1234567890']);

        $this->scan();

        $stored = (string) DB::table('identity_review_flags')->where('kind', 'nid')->value('match_hash');
        $this->assertNotSame(hash('sha256', '1234567890'), $stored);
        $this->assertNotSame(hash('sha256', 'nid|1234567890'), $stored);
        $this->assertSame(hash_hmac('sha256', 'nid|1234567890', (string) config('app.key')), $stored);
    }

    public function test_two_partners_paid_to_the_same_number_are_flagged_across_formats(): void
    {
        $first = User::factory()->dealer()->create();
        $second = User::factory()->subDealer()->create();
        $this->withdrawalFor($first, '01712345678');
        $this->withdrawalFor($second, '+880 1712-345678');
        $this->withdrawalFor($first, '01712345678');

        $this->scan();

        $flag = DB::table('identity_review_flags')->where('kind', 'payout')->sole();
        $this->assertSame([$first->id, $second->id], json_decode((string) $flag->user_ids, true));
    }

    public function test_one_partner_using_one_number_twice_is_not_flagged(): void
    {
        $partner = User::factory()->dealer()->create();
        $this->withdrawalFor($partner, '01712345678');
        $this->withdrawalFor($partner, '01712345678');

        $this->scan();

        $this->assertDatabaseCount('identity_review_flags', 0);
    }

    public function test_a_shared_phone_is_flagged(): void
    {
        $owner = User::factory()->staff()->create();
        $first = User::factory()->dealer()->create(['phone' => '01711111111', 'phone_share_approved_by' => $owner->id]);
        $second = User::factory()->dealer()->create(['phone' => '8801711111111', 'phone_share_approved_by' => $owner->id]);

        $this->scan();

        $flag = DB::table('identity_review_flags')->where('kind', 'phone')->sole();
        $this->assertSame([$first->id, $second->id], json_decode((string) $flag->user_ids, true));
    }

    public function test_someone_referring_themselves_through_a_chain_is_flagged(): void
    {
        $top = User::factory()->dealer()->create(['nid' => '5550001111']);
        $middle = User::factory()->dealer()->create(['sponsor_id' => $top->id]);
        $bottom = User::factory()->dealer()->create(['sponsor_id' => $middle->id, 'nid' => '5550001111']);

        $this->scan();

        $flag = DB::table('identity_review_flags')->where('kind', 'self_referral')->sole();
        $this->assertSame([$top->id, $bottom->id], json_decode((string) $flag->user_ids, true));
        $this->assertSame(['nid'], json_decode((string) $flag->via, true));
    }

    public function test_matching_accounts_on_separate_branches_of_the_tree_are_not_self_referral(): void
    {
        $sponsor = User::factory()->dealer()->create();
        User::factory()->dealer()->create(['sponsor_id' => $sponsor->id, 'nid' => '7770001111']);
        User::factory()->dealer()->create(['sponsor_id' => $sponsor->id, 'nid' => '7770001111']);

        $this->scan();

        $this->assertDatabaseHas('identity_review_flags', ['kind' => 'nid']);
        $this->assertDatabaseMissing('identity_review_flags', ['kind' => 'self_referral']);
    }

    public function test_the_scan_changes_nothing_on_the_accounts(): void
    {
        $sponsor = User::factory()->dealer()->create(['nid' => '3330001111']);
        $member = User::factory()->dealer()->create(['sponsor_id' => $sponsor->id, 'nid' => '3330001111']);
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        $walletsBefore = DB::table('wallets')->count();
        $commissionsBefore = DB::table('commission_allocations')->count();

        $this->scan();

        $this->assertDatabaseHas('identity_review_flags', ['kind' => 'self_referral']);
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
        $this->assertSame($walletsBefore, DB::table('wallets')->count());
        $this->assertSame($commissionsBefore, DB::table('commission_allocations')->count());
        $this->assertSame('active', $member->fresh()->status);
    }

    public function test_a_checked_match_stays_checked_until_a_new_account_joins_it(): void
    {
        $owner = User::factory()->staff()->create();
        User::factory()->dealer()->create(['nid' => '4440001111']);
        User::factory()->dealer()->create(['nid' => '4440001111']);
        $this->scan();
        $flagId = (int) DB::table('identity_review_flags')->value('id');
        $this->actingAs($owner)->onPortal('sadmin')
            ->postJson('/commands/identity-review/'.$flagId.'/mark', ['status' => 'cleared', 'note' => 'Family shop'])->assertOk();

        $this->scan();
        $this->assertSame('cleared', DB::table('identity_review_flags')->where('id', $flagId)->value('status'));

        User::factory()->dealer()->create(['nid' => '4440001111']);
        $this->scan();
        $this->assertSame('open', DB::table('identity_review_flags')->where('id', $flagId)->value('status'));
    }

    public function test_a_match_that_went_away_leaves_the_list(): void
    {
        $first = User::factory()->dealer()->create(['nid' => '6660001111']);
        User::factory()->dealer()->create(['nid' => '6660001111']);
        $this->scan();

        $first->forceFill(['nid' => '6660002222'])->save();
        $this->travel(1)->minutes();
        $this->scan();

        $this->assertSame('resolved', DB::table('identity_review_flags')->value('status'));
    }

    public function test_the_list_shows_masked_phones_and_never_the_nid_or_payout_number(): void
    {
        $owner = User::factory()->staff()->create();
        $first = User::factory()->dealer()->create(['name' => 'Rahim One', 'phone' => '01722223333', 'nid' => '8880001111']);
        $second = User::factory()->dealer()->create(['name' => 'Rahim Two', 'nid' => '8880001111']);
        $this->withdrawalFor($first, '01955556666');
        $this->withdrawalFor($second, '01955556666');
        $this->scan();

        $response = $this->actingAs($owner)->onPortal('sadmin')->getJson('/commands/identity-review')->assertOk();

        $response->assertJsonPath('data.open_count', 2)->assertJsonFragment(['name' => 'Rahim One', 'phone' => '••••3333']);
        $body = $response->getContent();
        foreach (['8880001111', '01955556666', '01722223333'] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
        $this->assertStringNotContainsString((string) DB::table('identity_review_flags')->value('match_hash'), $body);
    }

    public function test_only_the_owner_and_finance_can_open_the_list(): void
    {
        $this->actingAs(User::factory()->staff('md')->create())->onPortal('sadmin')->getJson('/commands/identity-review')->assertOk();
        $this->actingAs(User::factory()->staff('finance')->create())->onPortal('manage')->getJson('/commands/identity-review')->assertOk();
        $this->actingAs(User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]))->onPortal('manage')->getJson('/commands/identity-review')->assertForbidden();
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->getJson('/commands/identity-review')->assertForbidden();
    }

    public function test_finance_staff_can_run_the_check_and_mark_a_match(): void
    {
        User::factory()->dealer()->create(['nid' => '1110001111']);
        User::factory()->dealer()->create(['nid' => '1110001111']);
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/identity-review/scan')->assertOk()->assertJsonPath('data.found', 1);
        $flagId = (int) DB::table('identity_review_flags')->value('id');
        $this->actingAs($finance)->onPortal('manage')->postJson('/commands/identity-review/'.$flagId.'/mark', ['status' => 'cleared'])->assertOk();

        $this->assertDatabaseHas('identity_review_flags', ['id' => $flagId, 'status' => 'cleared', 'reviewed_by' => $finance->id]);
    }

    public function test_a_compulsory_note_is_asked_when_clearing_a_match_but_not_when_opening_it_again(): void
    {
        $owner = User::factory()->staff('md')->create();
        User::factory()->dealer()->create(['nid' => '3330001111']);
        User::factory()->dealer()->create(['nid' => '3330001111']);
        $this->scan();
        $this->reasonFields(['partner.identity_review' => 'required']);
        $flagId = (int) DB::table('identity_review_flags')->value('id');
        $address = '/commands/identity-review/'.$flagId.'/mark';

        $this->actingAs($owner)->onPortal('sadmin')->postJson($address, ['status' => 'cleared'])->assertJsonValidationErrors('note');
        $this->assertSame('open', DB::table('identity_review_flags')->where('id', $flagId)->value('status'));

        $this->actingAs($owner)->onPortal('sadmin')->postJson($address, ['status' => 'cleared', 'note' => 'Father and son'])->assertOk();
        $this->actingAs($owner)->onPortal('sadmin')->postJson($address, ['status' => 'open'])->assertOk();
        $this->assertSame('open', DB::table('identity_review_flags')->where('id', $flagId)->value('status'));
    }

    public function test_staff_without_finance_cannot_run_or_mark(): void
    {
        $orders = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['orders']]]);

        $this->actingAs($orders)->onPortal('manage')->postJson('/commands/identity-review/scan')->assertForbidden();
        $this->actingAs($orders)->onPortal('manage')->postJson('/commands/identity-review/1/mark', ['status' => 'cleared'])->assertForbidden();
    }

    public function test_the_owner_can_run_the_check_from_the_page(): void
    {
        User::factory()->dealer()->create(['nid' => '2220001111']);
        User::factory()->dealer()->create(['nid' => '2220001111']);

        $this->actingAs(User::factory()->staff('chairman')->create())->onPortal('sadmin')->postJson('/commands/identity-review/scan')
            ->assertOk()
            ->assertJsonPath('data.found', 1)
            ->assertJsonPath('data.opened', 1);
    }

    private function scan(): void
    {
        app(DuplicateIdentityScan::class)->run();
    }

    private function withdrawalFor(User $partner, string $destination): void
    {
        DB::table('withdrawals')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $partner->id,
            'amount' => '500.00',
            'status' => 'requested',
            'channel' => 'bKash',
            'destination' => encrypt($destination),
            'idempotency_key' => (string) Str::uuid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
