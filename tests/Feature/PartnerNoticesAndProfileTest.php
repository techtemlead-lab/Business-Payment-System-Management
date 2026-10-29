<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerNoticesAndProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_unread_notice_count_is_shared_and_mark_all_clears_it(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        DB::table('account_notices')->insert([
            ['user_id' => $dealer->id, 'title' => 'Stock ready', 'body' => 'Product A is available.', 'read_at' => null, 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $dealer->id, 'title' => 'Order update', 'body' => 'Order shipped.', 'read_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('unreadNotices', 2)
                ->where('navAttention.notifications', 2)
                ->where('navAttention.enquiries', 0)
                ->where('navAttention.withdrawals', 0)
                ->has('data.notices.data', 2));

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/notices/read-all')
            ->assertRedirect();

        $this->assertSame(0, (int) DB::table('account_notices')->where('user_id', $dealer->id)->whereNull('read_at')->count());

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unreadNotices', 0)->where('navAttention.notifications', 0));
    }

    public function test_staff_nav_attention_counts_enquiries_withdrawals_and_support(): void
    {
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        DB::table('enquiries')->insert([
            'name' => 'New lead',
            'phone' => '01700000001',
            'topic' => 'partnership',
            'message' => 'Interested in dealership',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('withdrawals')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $partner->id,
            'amount' => 1000,
            'channel' => 'bKash',
            'destination' => '01700000002',
            'idempotency_key' => 'nav-attention-wd-1',
            'status' => 'requested',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('support_tickets')->insert([
            'id' => (string) Str::uuid(),
            'user_id' => $partner->id,
            'category' => 'account',
            'subject' => 'Need help',
            'body' => 'Please review my account',
            'status' => 'pending',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($staff)->onPortal('sadmin')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('navAttention.enquiries', 1)
                ->where('navAttention.withdrawals', 1)
                ->where('navAttention.support-admin', 1));
    }

    public function test_partner_profile_exposes_kyc_summary_without_raw_identity_fields(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'refer_code' => 'REF-KYC-01',
            'nid' => null,
            'profile' => [
                'name_bn' => 'ডিলার',
                'shop_name' => 'Madina Care Point',
                'place_code' => 'MNHC-101',
                'identity_type' => 'birth_certificate',
                'birth_certificate_number' => '19901234567890123',
                'date_of_birth' => '1990-01-15',
                'upazila' => 'Pirganj',
            ],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.profile.kyc.identity_type', 'birth_certificate')
                ->where('data.profile.kyc.identity_status', 'on_file')
                ->where('data.profile.kyc.identity_label', 'Birth certificate')
                ->where('data.profile.shop_name', 'Madina Care Point')
                ->where('data.profile.refer_code', 'REF-KYC-01')
                ->missing('data.profile.fields')
                ->missing('data.profile.nid_status'));
    }
}
