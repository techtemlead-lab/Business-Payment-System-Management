<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class NotificationDropdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_latest_notices_are_the_users_own_newest_fifteen_with_the_unread_count(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        for ($i = 1; $i <= 17; $i++) {
            $this->notice($dealer, 'Notice '.$i, $i <= 3 ? now()->subDay() : null, now()->subMinutes(100 - $i));
        }
        $this->notice($other, 'Someone else', null, now());

        $response = $this->actingAs($dealer)->onPortal('dealer')
            ->getJson('/commands/notices/latest')
            ->assertOk()
            ->assertJsonCount(15, 'data.notices')
            ->assertJsonPath('data.unread', 14)
            ->assertJsonPath('data.notices.0.title', 'Notice 17')
            ->assertJsonPath('data.notices.14.title', 'Notice 3');

        $this->assertNotContains('Someone else', array_column($response->json('data.notices'), 'title'));
        $this->assertNotNull($response->json('data.notices.14.read_at'));
        $this->assertNull($response->json('data.notices.0.read_at'));
    }

    public function test_latest_notices_list_is_empty_for_a_new_account(): void
    {
        $dealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('subdealer')
            ->getJson('/commands/notices/latest')
            ->assertOk()
            ->assertJsonPath('data.notices', [])
            ->assertJsonPath('data.unread', 0);
    }

    public function test_marking_one_notice_read_returns_the_new_unread_count_and_only_touches_own_notices(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $first = $this->notice($dealer, 'First');
        $this->notice($dealer, 'Second');
        $foreign = $this->notice($other, 'Not yours');

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/notices/'.$first.'/read')
            ->assertOk()
            ->assertJsonPath('data.unread', 1);

        $this->assertNotNull(DB::table('account_notices')->where('id', $first)->value('read_at'));

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/notices/'.$foreign.'/read')
            ->assertNotFound();

        $this->assertNull(DB::table('account_notices')->where('id', $foreign)->value('read_at'));
    }

    public function test_mark_all_read_as_json_clears_only_the_users_notices(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->notice($dealer, 'One');
        $this->notice($dealer, 'Two');
        $this->notice($other, 'Theirs');

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/notices/read-all')
            ->assertOk()
            ->assertJsonPath('data.unread', 0);

        $this->assertSame(0, DB::table('account_notices')->where('user_id', $dealer->id)->whereNull('read_at')->count());
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $other->id)->whereNull('read_at')->count());
    }

    public function test_the_notices_page_form_posts_still_redirect_back(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $id = $this->notice($dealer, 'One');

        $this->actingAs($dealer)->onPortal('dealer')
            ->from('/notifications')
            ->post('/commands/notices/'.$id.'/read')
            ->assertRedirect('/notifications');
    }

    public function test_guests_cannot_read_the_notice_list(): void
    {
        $this->onPortal('dealer')
            ->getJson('/commands/notices/latest')
            ->assertUnauthorized();
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function portalAccounts(): array
    {
        return [
            'owner' => ['sadmin', 'super_admin'],
            'office staff' => ['manage', 'finance'],
            'dealer' => ['dealer', 'dealer'],
            'sub-dealer' => ['subdealer', 'sub_dealer'],
        ];
    }

    #[DataProvider('portalAccounts')]
    public function test_every_portal_can_load_its_bell_panel(string $portal, string $role): void
    {
        $user = User::factory()->create(['role' => $role, 'status' => 'active']);
        $this->notice($user, 'Hello');

        $this->actingAs($user)->onPortal($portal)
            ->getJson('/commands/notices/latest')
            ->assertOk()
            ->assertJsonCount(1, 'data.notices')
            ->assertJsonPath('data.unread', 1);
    }

    private function notice(User $user, string $title, mixed $readAt = null, mixed $createdAt = null): int
    {
        return (int) DB::table('account_notices')->insertGetId([
            'user_id' => $user->id,
            'title' => $title,
            'body' => $title.' body',
            'read_at' => $readAt,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }
}
