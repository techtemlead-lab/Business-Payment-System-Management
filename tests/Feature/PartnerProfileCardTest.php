<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerProfileCard;
use App\Domain\Accounts\PartnerProfileLock;
use App\Domain\Orders\OrderInvoice;
use App\Models\Order;
use App\Models\ReferralTreePath;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The partner's own identity card, referral visibility, the joining form and the profile lock.
 */
class PartnerProfileCardTest extends TestCase
{
    use RefreshDatabase;

    private User $sponsor;

    private User $partner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sponsor = User::factory()->dealer()->create(['status' => 'active', 'name' => 'Rahim Sponsor', 'dealer_code' => 'MNHC100', 'refer_code' => 'REF100', 'division' => 'Dhaka', 'district' => 'Dhaka']);
        $this->partner = User::factory()->subDealer()->create([
            'status' => 'active', 'name' => 'Karim Partner', 'dealer_code' => 'MNHC200', 'refer_code' => 'REF200',
            'sponsor_id' => $this->sponsor->id, 'division' => 'Dhaka', 'district' => 'Gazipur',
            'profile' => ['upazila' => 'Kaliakair', 'union' => 'Mouchak', 'village' => 'Mouchak Bazar', 'shop_name' => 'Karim Pharmacy', 'place_code' => 'BD-1-2-3'],
        ]);
        ReferralTreePath::attachUser($this->sponsor->id, null);
        ReferralTreePath::attachUser($this->partner->id, $this->sponsor->id);
    }

    private function deliveredOrder(User $buyer, string $total): Order
    {
        return Order::create([
            'user_id' => $buyer->id, 'number' => 'PC-'.Str::random(6), 'status' => 'delivered', 'total' => $total, 'paid' => $total,
            'idempotency_key' => (string) Str::uuid(), 'payload_hash' => hash('sha256', Str::random(8)),
        ]);
    }

    public function test_the_card_shows_type_code_full_address_status_joined_and_who_referred_me(): void
    {
        $card = app(PartnerProfileCard::class)->for($this->partner);

        $this->assertSame('sub_dealer', $card['role']);
        $this->assertSame('MNHC200', $card['dealer_code']);
        $this->assertSame('BD-1-2-3', $card['place_code']);
        $this->assertSame(['division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Kaliakair', 'union' => 'Mouchak', 'village' => 'Mouchak Bazar'], $card['address']);
        $this->assertSame([], $card['address_missing']);
        $this->assertSame('active', $card['status']);
        $this->assertNotNull($card['joined_on']);
        $this->assertSame(['kind' => 'partner', 'name' => 'Rahim Sponsor', 'code' => 'MNHC100', 'phone' => $this->sponsor->phone], $card['referred_by']);
        $this->assertStringEndsWith('/join/REF200', (string) $card['refer_url']);
    }

    public function test_an_incomplete_address_is_named_and_a_partner_without_a_sponsor_is_shown_as_company(): void
    {
        $loose = User::factory()->subDealer()->create(['status' => 'active', 'division' => 'Dhaka', 'district' => null, 'profile' => ['referred_by' => 'management']]);

        $card = app(PartnerProfileCard::class)->for($loose);

        $this->assertSame(['district', 'upazila', 'union'], $card['address_missing']);
        $this->assertSame('company', $card['referred_by']['kind']);
    }

    public function test_my_referrals_count_direct_and_team_and_each_referrals_delivered_purchases(): void
    {
        $grandchild = User::factory()->subDealer()->create(['status' => 'active', 'sponsor_id' => $this->partner->id]);
        ReferralTreePath::attachUser($grandchild->id, $this->partner->id);
        $this->deliveredOrder($this->partner, '1000.00');
        $this->deliveredOrder($grandchild, '500.00');
        Order::create(['user_id' => $grandchild->id, 'number' => 'PC-NOT', 'status' => 'pending', 'total' => '9999.00', 'paid' => '0.00', 'idempotency_key' => (string) Str::uuid(), 'payload_hash' => 'x']);

        $referrals = app(PartnerProfileCard::class)->for($this->sponsor)['referrals'];

        $this->assertSame(1, $referrals['direct']);
        $this->assertSame(2, $referrals['team']);
        $this->assertSame('1500.00', $referrals['sales_total']);
        $this->assertCount(1, $referrals['items']);
        $this->assertSame('Karim Partner', $referrals['items'][0]['name']);
        $this->assertSame('1000.00', $referrals['items'][0]['own_sales']);
        $this->assertSame('1500.00', $referrals['items'][0]['branch_sales']);
        $this->assertSame(1, $referrals['items'][0]['branch_members']);
    }

    public function test_a_dealer_sees_the_rank_ladder_and_a_sub_dealer_does_not(): void
    {
        $dealerCard = app(PartnerProfileCard::class)->for($this->sponsor);
        $this->assertSame('dealer', $dealerCard['rank']['current']);
        $this->assertSame(['mo', 'am', 'sm', 'me'], array_column($dealerCard['rank']['ladder'], 'rank'));
        $this->assertSame('own_purchases', $dealerCard['rank']['ladder'][0]['kind']);
        $this->assertSame('team_leaders', $dealerCard['rank']['ladder'][1]['kind']);
        $this->assertNull(app(PartnerProfileCard::class)->for($this->partner)['rank']);
    }

    public function test_the_profile_page_carries_the_card_for_a_partner_and_not_for_staff(): void
    {
        $this->actingAs($this->partner)->onPortal('subdealer')->get('/profile')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.profile.partner_card.referred_by.name', 'Rahim Sponsor')
                ->where('data.profile.partner_card.address.union', 'Mouchak')
                ->where('data.profile.partner_card.locked', true));

        $owner = User::factory()->staff('super_admin')->create();
        $this->actingAs($owner)->onPortal('sadmin')->get('/profile')->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.profile.partner_card', null));
    }

    public function test_every_partner_invoice_names_who_referred_the_buyer(): void
    {
        $order = $this->deliveredOrder($this->partner, '100.00');
        $payload = app(OrderInvoice::class)->payload($order->fresh(['user', 'lines']), 'dealer');
        $this->assertSame('Rahim Sponsor', $payload['buyer']['referred_name']);
        $this->assertSame('MNHC100', $payload['buyer']['referred_code']);
        $html = view('invoices.order-letter', $payload)->render();
        $this->assertStringContainsString('Rahim Sponsor', $html);

        $direct = User::factory()->dealer()->create(['status' => 'active', 'sponsor_id' => null]);
        $directPayload = app(OrderInvoice::class)->payload($this->deliveredOrder($direct, '100.00')->fresh(['user', 'lines']), 'dealer');
        $this->assertTrue($directPayload['buyer']['referred_company']);
        $this->assertStringContainsString(e(__('partner_profile.referred_by_company')), view('invoices.order-letter', $directPayload)->render());
    }

    public function test_new_profiles_start_locked_and_a_locked_partner_cannot_edit_anything(): void
    {
        Storage::fake('public');
        $this->assertTrue(PartnerProfileLock::isLocked($this->partner));

        $this->actingAs($this->partner)->onPortal('subdealer')
            ->post('/commands/profile/details', ['shop_name' => 'Changed', 'village' => 'Changed'])->assertForbidden();
        $this->actingAs($this->partner)->onPortal('subdealer')
            ->post('/commands/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 120, 120)])->assertForbidden();
        $this->assertSame('Karim Pharmacy', $this->partner->fresh()->profile['shop_name']);
    }

    public function test_office_can_open_and_lock_a_profile_with_an_audit_trail_and_a_notice(): void
    {
        Storage::fake('public');
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post("/commands/accounts/{$this->partner->id}/profile-lock", ['locked' => 0, 'reason' => 'He asked to fix his shop'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertFalse(PartnerProfileLock::isLocked($this->partner->fresh()));
        $this->assertDatabaseHas('audit_events', ['action' => 'accounts.profile_lock_changed', 'subject' => (string) $this->partner->id]);
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $this->partner->id)->count());
        $partner = $this->partner->fresh();

        $this->actingAs($partner)->onPortal('subdealer')
            ->post('/commands/profile/details', ['shop_name' => 'New Shop', 'village' => 'New Village'])->assertRedirect()->assertSessionHas('success');
        $profile = $this->partner->fresh()->profile;
        $this->assertSame('New Shop', $profile['shop_name']);
        $this->assertSame('New Village', $profile['village']);
        $this->assertSame('Kaliakair', $profile['upazila']);
        $this->actingAs($this->partner->fresh())->onPortal('subdealer')
            ->post('/commands/profile/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 120, 120)])->assertRedirect()->assertSessionHas('success');

        $this->actingAs($owner)->onPortal('sadmin')
            ->post("/commands/accounts/{$this->partner->id}/profile-lock", ['locked' => 1])->assertRedirect();
        $this->assertTrue(PartnerProfileLock::isLocked($this->partner->fresh()));
        $this->actingAs($this->partner->fresh())->onPortal('subdealer')
            ->post('/commands/profile/details', ['shop_name' => 'Again'])->assertForbidden();
    }

    public function test_only_office_staff_with_account_rights_can_flip_the_lock(): void
    {
        $this->actingAs($this->partner)->onPortal('subdealer')
            ->post("/commands/accounts/{$this->partner->id}/profile-lock", ['locked' => 0])->assertForbidden();
        $this->actingAs($this->sponsor)->onPortal('dealer')
            ->post("/commands/accounts/{$this->partner->id}/profile-lock", ['locked' => 0])->assertForbidden();
        $this->assertTrue(PartnerProfileLock::isLocked($this->partner->fresh()));
    }

    /** @return array<string, string> */
    private function application(array $over = []): array
    {
        return $over + ['name' => 'New Person', 'email' => 'new.person@example.test', 'phone' => '01811119999', 'date_of_birth' => '1990-01-15', 'role' => 'sub_dealer'];
    }

    public function test_the_join_form_needs_only_the_referrer_code_and_takes_the_place_from_him(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application(['sponsor_code' => 'ref100']))
            ->assertSessionHasNoErrors();

        $data = json_decode((string) DB::table('dealer_applications')->value('data'), true);
        $this->assertSame('REF100', $data['sponsor_code']);
        $this->assertSame('Dhaka', $data['division']);
        $this->assertSame('Dhaka', $data['district']);
    }

    public function test_a_wrong_referrer_code_is_refused_and_no_place_and_no_referrer_is_refused(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application(['sponsor_code' => 'NOPE999']))
            ->assertSessionHasErrors('sponsor_code');
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application())
            ->assertSessionHasErrors('division');
        $this->assertSame(0, DB::table('dealer_applications')->count());
    }

    public function test_the_partnership_page_opens_with_the_referrer_code_and_name_filled_from_the_link(): void
    {
        $this->onPortal('public')->get('/partnership?sponsor_code=REF100')->assertOk()
            ->assertSee('value="REF100"', false)
            ->assertSee(e(__('partner_profile.form_referrer_found', ['name' => 'Rahim Sponsor'])), false);
    }
}
