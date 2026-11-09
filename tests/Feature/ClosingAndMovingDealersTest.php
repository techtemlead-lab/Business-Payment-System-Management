<?php

namespace Tests\Feature;

use App\Domain\Accounts\DealerVerification;
use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Audience\PartnerProductEnquiries;
use App\Models\AreaDealerAssignment;
use App\Models\PlaceCode;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * A dealer's code is the code of the place they hold. Closing gives the place up, reopening
 * never takes it from someone else, and moving carries the code to the new place while old
 * links and all history stay with the dealer.
 */
class ClosingAndMovingDealersTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => [], 'madina.preview_mode' => false]);
        $this->seedClassicPlaceCodes();
        $this->owner = User::factory()->staff('super_admin')->create(['phone' => '01900000001']);
    }

    public function test_closing_a_dealer_frees_their_seat(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102');

        $this->close($dealer)->assertSessionHas('success');

        $this->assertNotNull($dealer->fresh()->closed_at);
        $this->assertSame([], $this->activeSeatCodes($dealer));
        $this->assertDatabaseHas('area_dealer_assignments', ['user_id' => $dealer->id, 'is_active' => false, 'territory_key' => null]);
    }

    public function test_the_freed_place_can_be_given_to_someone_else_with_the_place_code(): void
    {
        $closed = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($closed);

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm('MNHC-102'))
            ->assertSessionHasNoErrors()
            ->assertRedirect('/accounts');

        $replacement = User::query()->where('email', 'replacement@example.test')->firstOrFail();
        $this->assertSame('MNHC-102', $replacement->dealer_code);
        $this->assertSame('MNHC-102', $replacement->refer_code);
        $this->assertSame(['MNHC-102'], $this->activeSeatCodes($replacement));

        $closed->refresh();
        $this->assertSame('MNHC-102-CLOSED-'.$closed->id, $closed->dealer_code);
        $this->assertSame('MNHC-102-CLOSED-'.$closed->id, $closed->refer_code);
        $this->assertSame(1, User::query()->where('sponsor_id', $closed->id)->count(), 'The team stays with the closed account.');
    }

    public function test_an_application_can_also_take_a_freed_place(): void
    {
        $closed = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($closed);

        $place = PlaceCode::findByCode('MNHC-102');
        $this->assertSame('MNHC-102', app(PartnerAccountProvisioning::class)->codeForPlace($this->owner, $place, 'dealer'));
        $this->assertSame('MNHC-102-CLOSED-'.$closed->id, $closed->fresh()->dealer_code);
    }

    public function test_reopening_when_the_place_is_still_free_gives_it_back(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($dealer);

        $this->reopen($dealer)->assertSessionHasNoErrors()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertNull($dealer->closed_at);
        $this->assertSame('active', $dealer->status);
        $this->assertSame('MNHC-102', $dealer->dealer_code);
        $this->assertSame(['MNHC-102'], $this->activeSeatCodes($dealer));
    }

    public function test_reopening_is_refused_with_a_reason_when_someone_else_holds_the_place(): void
    {
        $closed = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($closed);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm('MNHC-102'))->assertSessionHasNoErrors();

        $this->reopen($closed)->assertSessionHasErrors([
            'status' => __('portal.acct_val_reopen_place_taken', ['place' => 'MNHC-102', 'holder' => 'MNHC-102']),
        ]);

        $closed->refresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame('suspended', $closed->status);
        $this->assertSame([], $this->activeSeatCodes($closed));
        $replacement = User::query()->where('email', 'replacement@example.test')->firstOrFail();
        $this->assertSame(['MNHC-102'], $this->activeSeatCodes($replacement));
    }

    public function test_reopening_together_with_a_free_place_seats_the_dealer_there(): void
    {
        $closed = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($closed);
        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm('MNHC-102'))->assertSessionHasNoErrors();

        $this->reopen($closed, ['place_code' => 'MNHC-103'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $closed->refresh();
        $this->assertNull($closed->closed_at);
        $this->assertSame('MNHC-103', $closed->dealer_code);
        $this->assertSame('MNHC-103', $closed->refer_code);
        $this->assertSame(['MNHC-103'], $this->activeSeatCodes($closed));
    }

    public function test_a_closed_dealer_cannot_take_a_new_place_without_being_reopened(): void
    {
        $closed = $this->seatedDealerWithHistory('MNHC-102');
        $this->close($closed);

        $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$closed->id, [
            'name' => $closed->name,
            'place_code' => 'MNHC-103',
            'reason' => 'Trying to move a closed account',
        ])->assertSessionHasErrors(['place_code' => __('portal.acct_val_closed_dealer_move')]);

        $this->assertSame([], $this->activeSeatCodes($closed));
        $this->assertSame('MNHC-102', $closed->fresh()->dealer_code);
    }

    public function test_moving_a_dealer_changes_the_code_and_keeps_old_links_and_history(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102');

        $this->move($dealer, 'MNHC-103')->assertSessionHasNoErrors()->assertSessionHas('success');

        $dealer->refresh();
        $this->assertSame('MNHC-103', $dealer->dealer_code);
        $this->assertSame('MNHC-103', $dealer->refer_code);
        $this->assertSame('MNHC-103', $dealer->profile['area_code']);
        $this->assertSame(1, User::query()->where('sponsor_id', $dealer->id)->count(), 'The team stays attached.');
        $this->assertDatabaseHas('audit_events', ['action' => 'account.geography_updated', 'subject' => (string) $dealer->id]);

        $partners = app(PartnerAccountProvisioning::class);
        $this->assertSame($dealer->id, $partners->findSponsor('MNHC-102')?->id, 'An application with the old code still reaches the dealer.');
        $this->assertSame($dealer->id, app(PartnerProductEnquiries::class)->partnerForCode('MNHC-102')?->id);
        $this->assertSame((string) $dealer->name, app(DealerVerification::class)->lookup('MNHC-102')['name'] ?? null, 'An old card still checks out.');
        $this->onPortal('public')->get('/join/MNHC-102')->assertOk()->assertSee('MNHC-103');
    }

    public function test_a_moved_dealer_is_told_the_new_code(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102');

        $this->move($dealer, 'MNHC-103');

        $this->assertDatabaseHas('account_notices', [
            'user_id' => $dealer->id,
            'title' => __('portal.notice_dealer_code_changed_title', [], $dealer->preferredLocale()),
            'body' => __('portal.notice_dealer_code_changed_body', ['new' => 'MNHC-103', 'old' => 'MNHC-102'], $dealer->preferredLocale()),
        ]);
    }

    public function test_a_custom_referral_code_is_left_alone_on_a_move(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102', ['refer_code' => 'MY-OWN-CODE']);

        $this->move($dealer, 'MNHC-103')->assertSessionHasNoErrors();

        $this->assertSame('MNHC-103', $dealer->fresh()->dealer_code);
        $this->assertSame('MY-OWN-CODE', $dealer->fresh()->refer_code);
    }

    public function test_after_a_move_the_old_place_and_its_code_go_to_the_next_dealer(): void
    {
        $moved = $this->seatedDealerWithHistory('MNHC-102');
        $this->move($moved, 'MNHC-103')->assertSessionHasNoErrors();

        $this->actingAs($this->owner)->onPortal('sadmin')->post('/commands/accounts', $this->dealerForm('MNHC-102'))->assertSessionHasNoErrors();

        $next = User::query()->where('email', 'replacement@example.test')->firstOrFail();
        $this->assertSame('MNHC-102', $next->dealer_code);
        $this->assertSame($next->id, app(PartnerAccountProvisioning::class)->findSponsor('MNHC-102')?->id);
        $this->assertDatabaseMissing('partner_former_codes', ['code' => 'MNHC-102']);
    }

    public function test_moving_onto_a_code_someone_active_uses_is_refused(): void
    {
        $dealer = $this->seatedDealerWithHistory('MNHC-102');
        User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'OTHER-1', 'refer_code' => 'MNHC-103']);

        $this->move($dealer, 'MNHC-103')->assertSessionHasErrors(['place_code' => __('portal.acct_val_dealer_code_taken')]);

        $this->assertSame('MNHC-102', $dealer->fresh()->dealer_code);
        $this->assertSame(['MNHC-102'], $this->activeSeatCodes($dealer));
    }

    public function test_deleting_a_moved_dealer_with_no_history_also_clears_the_old_code(): void
    {
        $dealer = $this->seatedDealer('MNHC-102');
        $this->move($dealer, 'MNHC-103')->assertSessionHasNoErrors();
        $this->assertDatabaseHas('partner_former_codes', ['user_id' => $dealer->id, 'code' => 'MNHC-102']);

        DB::table('audit_events')->where('actor_id', $dealer->id)->delete();
        $this->close($dealer)->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $dealer->id]);
        $this->assertDatabaseMissing('partner_former_codes', ['user_id' => $dealer->id]);
    }

    private function close(User $dealer): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->delete('/commands/accounts/'.$dealer->id, [
            'reason' => 'Dealer stopped working with us',
            'confirmation' => $dealer->fresh()->dealer_code,
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function reopen(User $dealer, array $extra = []): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'name' => $dealer->name,
            'status' => 'active',
            'reason' => 'Dealer came back to us',
            ...$extra,
        ]);
    }

    private function move(User $dealer, string $placeCode): TestResponse
    {
        return $this->actingAs($this->owner)->onPortal('sadmin')->patch('/commands/accounts/'.$dealer->id, [
            'name' => $dealer->name,
            'place_code' => $placeCode,
            'reason' => 'Office moved to a new area',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seatedDealer(string $placeCode, array $overrides = []): User
    {
        $place = PlaceCode::findByCode($placeCode);
        $dealer = User::factory()->dealer()->inPlace($place)->create(['status' => 'active', 'dealer_code' => $placeCode, 'refer_code' => $placeCode, ...$overrides]);
        ReferralTreePath::attachUser($dealer->id, null);
        app(PartnerAccountProvisioning::class)->seatDealer($dealer, $place);

        return $dealer;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function seatedDealerWithHistory(string $placeCode, array $overrides = []): User
    {
        $dealer = $this->seatedDealer($placeCode, $overrides);
        $child = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => $dealer->id]);
        ReferralTreePath::attachUser($child->id, $dealer->id);

        return $dealer;
    }

    /** @return list<string> */
    private function activeSeatCodes(User $dealer): array
    {
        return PlaceCode::query()
            ->whereIn('id', AreaDealerAssignment::query()->where('user_id', $dealer->id)->where('is_active', true)->pluck('place_code_id'))
            ->orderBy('code')
            ->pluck('code')
            ->all();
    }

    /** @return array<string, string> */
    private function dealerForm(string $placeCode): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456700',
            'date_of_birth' => '1985-05-01',
            'name' => 'Replacement Dealer',
            'name_bn' => 'নতুন ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => $placeCode,
            'phone' => '01710000077',
            'email' => 'replacement@example.test',
            'reason' => 'New dealer for this place',
        ];
    }
}
