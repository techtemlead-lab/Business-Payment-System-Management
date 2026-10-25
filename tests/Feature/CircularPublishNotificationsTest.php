<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CircularPublishNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_circular_notifies_active_partners_once(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $enDealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'en',
        ]);
        $bnSub = User::factory()->create([
            'role' => 'sub_dealer',
            'status' => 'active',
            'locale' => 'bn',
        ]);
        User::factory()->create([
            'role' => 'dealer',
            'status' => 'suspended',
            'locale' => 'en',
        ]);
        User::factory()->create([
            'role' => 'accounts',
            'status' => 'active',
            'locale' => 'en',
        ]);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'circular-training',
            'title' => 'April field training',
            'body' => 'Join the Pirganj training on Saturday morning.',
            'published' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('account_notices', [
            'user_id' => $enDealer->id,
            'title' => 'New training circular',
            'body' => 'A new training circular was published: April field training. Open Circulars in your partner portal to read it.',
        ]);
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $bnSub->id,
            'title' => 'নতুন প্রশিক্ষণ সার্কুলার',
        ]);
        $this->assertSame(2, DB::table('account_notices')->count());

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'circular-training',
            'title' => 'April field training (updated)',
            'body' => 'Updated body stays published.',
            'published' => true,
        ])->assertRedirect();

        $this->assertSame(2, DB::table('account_notices')->count());
    }

    public function test_draft_circular_and_non_partner_slugs_do_not_notify(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'en']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'circular-price',
            'title' => 'Draft price note',
            'body' => 'Should stay hidden.',
            'published' => false,
        ])->assertRedirect();

        $this->assertSame(0, DB::table('account_notices')->count());

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'about',
            'title' => 'About Madina',
            'body' => 'Public about page body.',
            'published' => true,
        ])->assertRedirect();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_republish_after_unpublish_notifies_again(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'locale' => 'en']);

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'Company notice A',
            'body' => 'First publish.',
            'published' => true,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->count());

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'Company notice A',
            'body' => 'Unpublished.',
            'published' => false,
        ])->assertRedirect();
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->count());

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'notice',
            'title' => 'Company notice B',
            'body' => 'Republished.',
            'published' => true,
        ])->assertRedirect();

        $this->assertSame(2, DB::table('account_notices')->where('user_id', $dealer->id)->count());
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $dealer->id,
            'title' => 'New company notice',
            'body' => 'A new company notice was published: Company notice B. Open Circulars in your partner portal to read it.',
        ]);
    }
}
