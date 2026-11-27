<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPosting;
use App\Domain\Orders\OrderService;
use App\Models\Order;
use App\Models\PlaceCode;
use App\Models\Product;
use App\Models\ReferralTreePath;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The client's request of 2026-11-27: on the account editor the office changes who referred a
 * dealer or sub-dealer (by the referrer's code) and every address, while the partner's own code
 * stays fixed. A new referrer counts for new orders only; earlier commission is never touched.
 */
class PartnerReferrerEditTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $oldReferrer;

    private User $newReferrer;

    private User $partner;

    private User $partnersTeamMember;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->owner = User::factory()->create(['role' => 'super_admin', 'phone' => '01811111111']);
        $this->oldReferrer = $this->partnerAccount('dealer', 'REF-OLD', 'Old Referrer');
        $this->newReferrer = $this->partnerAccount('dealer', 'REF-NEW', 'New Referrer');
        $this->partner = $this->partnerAccount('sub_dealer', 'PARTNER-1', 'Moved Partner', $this->oldReferrer);
        $this->partnersTeamMember = $this->partnerAccount('sub_dealer', 'TEAM-1', 'Team Member', $this->partner);
    }

    public function test_the_referrer_is_changed_by_code_and_the_change_is_audited_with_old_and_new(): void
    {
        $this->edit(['sponsor_code' => 'ref-new', 'reason' => 'Wrong referrer at joining'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($this->newReferrer->id, $this->partner->fresh()->sponsor_id);
        $audit = DB::table('audit_events')->where('action', 'account.referrer_changed')->where('subject', (string) $this->partner->id)->sole();
        $payload = json_decode((string) $audit->metadata, true);
        $this->assertSame(['id' => $this->oldReferrer->id, 'name' => 'Old Referrer', 'code' => 'REF-OLD'], $payload['before']);
        $this->assertSame(['id' => $this->newReferrer->id, 'name' => 'New Referrer', 'code' => 'REF-NEW'], $payload['after']);
        $this->assertSame('orders_from_now', $payload['applies_to']);
        $this->assertSame('Wrong referrer at joining', $payload['reason']);
        $this->assertSame($this->owner->id, (int) $audit->actor_id);
    }

    public function test_the_moved_partner_takes_their_own_team_along_in_the_tree(): void
    {
        $this->edit(['sponsor_code' => 'REF-NEW', 'reason' => 'Move'])->assertSessionHasNoErrors();

        $this->assertSame(1, $this->depth($this->newReferrer, $this->partner));
        $this->assertSame(2, $this->depth($this->newReferrer, $this->partnersTeamMember));
        $this->assertNull($this->depth($this->oldReferrer, $this->partner));
        $this->assertNull($this->depth($this->oldReferrer, $this->partnersTeamMember));
        $this->assertSame($this->partner->id, $this->partnersTeamMember->fresh()->sponsor_id);
    }

    public function test_company_with_no_referrer_can_be_chosen(): void
    {
        $this->edit(['sponsor_code' => '', 'reason' => 'Joined through the office'])->assertSessionHasNoErrors();

        $this->assertNull($this->partner->fresh()->sponsor_id);
        $this->assertNull($this->depth($this->oldReferrer, $this->partner));
        $payload = json_decode((string) DB::table('audit_events')->where('action', 'account.referrer_changed')->value('metadata'), true);
        $this->assertNull($payload['after']);
    }

    public function test_someone_in_the_partners_own_team_cannot_become_their_referrer(): void
    {
        $this->edit(['sponsor_code' => 'TEAM-1', 'reason' => 'Loop'])
            ->assertSessionHasErrors(['sponsor_code' => __('portal.cmd_val_circular_sponsor')]);
        $this->edit(['sponsor_id' => $this->partnersTeamMember->id, 'reason' => 'Loop'])
            ->assertSessionHasErrors(['sponsor_id' => __('portal.cmd_val_circular_sponsor')]);

        $this->assertSame($this->oldReferrer->id, $this->partner->fresh()->sponsor_id);
    }

    public function test_the_partner_cannot_refer_themselves(): void
    {
        $this->edit(['sponsor_code' => 'PARTNER-1', 'reason' => 'Self'])
            ->assertSessionHasErrors(['sponsor_code' => __('portal.cmd_val_self_sponsor')]);
        $this->edit(['sponsor_id' => $this->partner->id, 'reason' => 'Self'])
            ->assertSessionHasErrors(['sponsor_id' => __('portal.cmd_val_self_sponsor')]);

        $this->assertSame($this->oldReferrer->id, $this->partner->fresh()->sponsor_id);
    }

    public function test_a_suspended_or_unknown_referrer_is_refused(): void
    {
        $this->partnerAccount('dealer', 'REF-PAUSED', 'Paused Referrer')->forceFill(['status' => 'suspended'])->save();
        $staff = User::factory()->staff('employee')->create(['dealer_code' => 'STAFF-9']);

        $this->edit(['sponsor_code' => 'REF-PAUSED', 'reason' => 'Paused'])
            ->assertSessionHasErrors(['sponsor_code' => __('portal.cmd_val_sponsor_active_dealer')]);
        $this->edit(['sponsor_code' => 'NOBODY-1', 'reason' => 'Typo'])
            ->assertSessionHasErrors(['sponsor_code' => __('portal.acct_val_reference_missing')]);
        $this->edit(['sponsor_id' => $staff->id, 'reason' => 'Staff'])
            ->assertSessionHasErrors(['sponsor_id' => __('portal.cmd_val_sponsor_active_dealer')]);

        $this->assertSame($this->oldReferrer->id, $this->partner->fresh()->sponsor_id);
        $this->assertSame(0, DB::table('audit_events')->where('action', 'account.referrer_changed')->count());
    }

    public function test_the_partners_own_code_stays_as_it_is(): void
    {
        $this->edit(['dealer_code' => 'NEW-CODE-1', 'reason' => 'Try to rename'])
            ->assertSessionHasErrors(['dealer_code' => __('portal.acct_val_dealer_code_locked')]);
        $this->edit(['dealer_code' => 'partner-1', 'name' => 'Same Code Kept', 'reason' => 'Same code'])
            ->assertSessionHasNoErrors();

        $partner = $this->partner->fresh();
        $this->assertSame('PARTNER-1', $partner->dealer_code);
        $this->assertSame('PARTNER-1', $partner->refer_code);
        $this->assertSame('Same Code Kept', $partner->name);
    }

    public function test_referrer_and_every_address_change_in_one_save(): void
    {
        $this->seedClassicPlaceCodes();
        $rajshahi = PlaceCode::query()->where('code', 'MNHC104')->firstOrFail();
        $bogura = PlaceCode::factory()->district($rajshahi)->create(['code' => 'MNHC304', 'name' => 'Bogura']);
        $sherpur = PlaceCode::factory()->upazila($bogura)->create(['code' => 'MNHC404', 'name' => 'Sherpur']);
        $union = PlaceCode::factory()->union($sherpur)->create(['code' => 'MNHC504', 'name' => 'Khamarkandi']);

        $this->edit([
            'sponsor_code' => 'REF-NEW',
            'permanent_place_id' => $union->id,
            'profile' => ['village' => 'Notun Para, House 4', 'post_office' => 'Sherpur', 'shop_name' => 'Notun Pharmacy'],
            'reason' => 'Corrected everything from the paper form',
        ])->assertSessionHasNoErrors();

        $partner = $this->partner->fresh();
        $this->assertSame($this->newReferrer->id, $partner->sponsor_id);
        $this->assertSame(['Rajshahi', 'Bogura', 'Sherpur', 'Khamarkandi'], [$partner->profile['permanent_division'], $partner->profile['permanent_district'], $partner->profile['permanent_upazila'], $partner->profile['permanent_union']]);
        $this->assertSame('Notun Para, House 4', $partner->profile['village']);
        $this->assertSame('Sherpur', $partner->profile['post_office']);
        $this->assertSame('Notun Pharmacy', $partner->profile['shop_name']);
        $this->assertSame('PARTNER-1', $partner->dealer_code);
    }

    public function test_only_people_who_edit_accounts_may_change_or_look_up_a_referrer(): void
    {
        $storeKeeper = User::factory()->staff('inventory')->create();
        $this->actingAs($storeKeeper)->onPortal('manage')
            ->patch('/commands/accounts/'.$this->partner->id, ['sponsor_code' => 'REF-NEW', 'reason' => 'Not mine'])
            ->assertForbidden();
        $this->actingAs($storeKeeper)->onPortal('manage')
            ->getJson('/commands/accounts/'.$this->partner->id.'/referrer-lookup?code=REF-NEW')
            ->assertForbidden();
        $this->assertSame($this->oldReferrer->id, $this->partner->fresh()->sponsor_id);

        $accountsStaff = User::factory()->staff('account_manager')->create();
        $this->actingAs($accountsStaff)->onPortal('manage')
            ->patch('/commands/accounts/'.$this->partner->id, ['sponsor_code' => 'REF-NEW', 'reason' => 'Accounts desk correction'])
            ->assertSessionHasNoErrors();
        $this->assertSame($this->newReferrer->id, $this->partner->fresh()->sponsor_id);
    }

    public function test_the_lookup_names_the_new_referrer_and_refuses_the_partners_own_team(): void
    {
        $grandReferrer = $this->partnerAccount('dealer', 'REF-TOP', 'Top Referrer');
        ReferralTreePath::reparentUser($this->newReferrer->id, $grandReferrer->id);

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/accounts/'.$this->partner->id.'/referrer-lookup?code=REF-NEW')
            ->assertOk()
            ->assertJsonPath('data.referrer', ['id' => $this->newReferrer->id, 'name' => 'New Referrer', 'code' => 'REF-NEW'])
            ->assertJsonPath('data.consumers.d2d_gen2.name', 'Top Referrer');

        $this->actingAs($this->owner)->onPortal('sadmin')
            ->getJson('/commands/accounts/'.$this->partner->id.'/referrer-lookup?code=TEAM-1')
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('portal.cmd_val_circular_sponsor'));
    }

    public function test_earlier_orders_and_their_commission_keep_the_old_referrer(): void
    {
        $this->enableFinancialPosting();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T06:00:00Z'));
        $posted = $this->purchase();
        $this->approve($posted);
        $posted->refresh()->update(['paid' => $posted->total, 'status' => 'delivered']);
        app(CommissionPosting::class)->process($posted->fresh());
        $placedBefore = $this->purchase();

        $allocationsBefore = DB::table('commission_allocations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all();
        $journalBefore = DB::table('journal_entries')->count();
        $postedSnapshot = $posted->fresh()->commission_policy_snapshot;
        $this->assertSame($this->oldReferrer->id, $postedSnapshot['uplines'][0]);
        $this->assertTrue(collect($allocationsBefore)->contains(fn (array $row): bool => (int) $row['user_id'] === $this->oldReferrer->id));

        $this->edit(['sponsor_code' => 'REF-NEW', 'reason' => 'Move after sales'])->assertSessionHasNoErrors();

        $this->assertSame($allocationsBefore, DB::table('commission_allocations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all());
        $this->assertSame($journalBefore, DB::table('journal_entries')->count());
        $this->assertSame($postedSnapshot, $posted->fresh()->commission_policy_snapshot);
        $this->assertSame($this->oldReferrer->id, $placedBefore->fresh()->commission_policy_snapshot['uplines'][0]);
        $this->assertSame($this->newReferrer->id, $this->purchase()->fresh()->commission_policy_snapshot['uplines'][0]);
        $this->travelBack();
    }

    /** @param array<string, mixed> $data */
    private function edit(array $data): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$this->partner->id, $data);
    }

    private function partnerAccount(string $role, string $code, string $name, ?User $referrer = null): User
    {
        $user = User::factory()->create([
            'role' => $role,
            'status' => 'active',
            'name' => $name,
            'dealer_code' => $code,
            'refer_code' => $code,
            'sponsor_id' => $referrer?->id,
        ]);
        ReferralTreePath::attachUser($user->id, $referrer?->id);

        return $user;
    }

    private function depth(User $ancestor, User $descendant): ?int
    {
        $depth = ReferralTreePath::query()->where('ancestor_id', $ancestor->id)->where('descendant_id', $descendant->id)->value('depth');

        return $depth === null ? null : (int) $depth;
    }

    private function purchase(): Order
    {
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00']);

        return app(OrderService::class)->create($this->partner->fresh(), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
    }

    private function approve(Order $order): void
    {
        $order->forceFill(['paid' => $order->fresh()->total])->save();
        $this->actingAs(User::factory()->staff('finance')->create())
            ->postJson('/api/v1/orders/'.$order->id.'/decision', ['status' => 'approved', 'reason' => 'Approve the current financial terms'])
            ->assertOk();
    }
}
