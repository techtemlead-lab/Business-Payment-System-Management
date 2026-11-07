<?php

namespace Tests\Feature;

use App\Domain\Notifications\WebPushNotices;
use App\Models\User;
use App\Notifications\AccountNoticePush;
use App\Support\Audit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Minishlink\WebPush\VAPID;
use NotificationChannels\WebPush\PushSubscription;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\TestCase;

class WebPushNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const Endpoint = 'https://push.example.test/send/device-1';

    private function enableWebPush(): string
    {
        $keys = VAPID::createVapidKeys();
        config([
            'webpush.vapid.public_key' => $keys['publicKey'],
            'webpush.vapid.private_key' => $keys['privateKey'],
            'webpush.vapid.subject' => 'mailto:owner@example.test',
        ]);

        return $keys['publicKey'];
    }

    /**
     * A browser subscription with real key material, so the payload can be encrypted.
     *
     * @return array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string}
     */
    private function browserSubscription(string $endpoint = self::Endpoint): array
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $details = openssl_pkey_get_details($key);
        $point = "\x04".str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT).str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT);
        $encode = fn (string $bytes): string => rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');

        return [
            'endpoint' => $endpoint,
            'keys' => ['p256dh' => $encode($point), 'auth' => $encode(random_bytes(16))],
            'content_encoding' => 'aes128gcm',
        ];
    }

    private function subscribe(User $user, ?array $subscription = null): void
    {
        $subscription ??= $this->browserSubscription();
        $user->updatePushSubscription($subscription['endpoint'], $subscription['keys']['p256dh'], $subscription['keys']['auth'], $subscription['content_encoding']);
    }

    public function test_feature_is_hidden_and_closed_without_vapid_keys(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('webPushKey', null));

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription())
            ->assertNotFound();
        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_dealer_can_turn_notifications_on_for_this_browser(): void
    {
        $publicKey = $this->enableWebPush();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('webPushKey', $publicKey));

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription())
            ->assertOk()
            ->assertJson(['subscribed' => true])
            ->assertSessionHas(WebPushNotices::SessionEndpoint, self::Endpoint);

        $this->assertTrue($dealer->pushSubscriptions()->where('endpoint', self::Endpoint)->exists());
    }

    public function test_sub_dealer_and_office_staff_can_turn_notifications_on(): void
    {
        $this->enableWebPush();
        $subDealer = User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']);
        $staff = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($subDealer)->onPortal('subdealer')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription('https://push.example.test/send/sub-dealer'))
            ->assertOk();
        $this->actingAs($staff)->onPortal('manage')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription('https://push.example.test/send/staff'))
            ->assertOk();

        $this->assertSame(1, $subDealer->pushSubscriptions()->count());
        $this->assertSame(1, $staff->pushSubscriptions()->count());
    }

    public function test_subscription_must_be_a_secure_push_endpoint_with_keys(): void
    {
        $this->enableWebPush();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/push-subscriptions', ['endpoint' => 'http://push.example.test/x', 'keys' => ['p256dh' => '', 'auth' => '']])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['endpoint', 'keys.p256dh', 'keys.auth']);
    }

    public function test_dealer_can_turn_notifications_off(): void
    {
        $this->enableWebPush();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($dealer);

        $this->actingAs($dealer)->onPortal('dealer')
            ->deleteJson('/commands/push-subscriptions', ['endpoint' => self::Endpoint])
            ->assertOk()
            ->assertJson(['subscribed' => false]);

        $this->assertSame(0, PushSubscription::query()->count());
    }

    public function test_turning_off_cannot_remove_another_users_subscription(): void
    {
        $this->enableWebPush();
        $owner = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($owner);

        $this->actingAs($other)->onPortal('dealer')
            ->deleteJson('/commands/push-subscriptions', ['endpoint' => self::Endpoint])
            ->assertOk();

        $this->assertTrue($owner->pushSubscriptions()->exists());
    }

    public function test_a_shared_phone_moves_the_subscription_to_the_new_user(): void
    {
        $this->enableWebPush();
        $first = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $second = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($first);

        $this->actingAs($second)->onPortal('dealer')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription())
            ->assertOk();

        $this->assertFalse($first->pushSubscriptions()->exists());
        $this->assertTrue($second->pushSubscriptions()->exists());
    }

    public function test_signing_out_stops_pushes_to_this_browser(): void
    {
        $this->enableWebPush();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($dealer, $this->browserSubscription('https://push.example.test/send/other-phone'));

        $this->actingAs($dealer)->onPortal('dealer')
            ->postJson('/commands/push-subscriptions', $this->browserSubscription())
            ->assertOk();
        $this->onPortal('dealer')->post('/logout')->assertRedirect();

        $this->assertSame(['https://push.example.test/send/other-phone'], $dealer->pushSubscriptions()->pluck('endpoint')->all());
    }

    public function test_notice_is_pushed_only_to_users_with_a_subscription(): void
    {
        $this->enableWebPush();
        Notification::fake();
        $subscribed = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $notSubscribed = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($subscribed);

        Audit::notice($subscribed->id, 'Order approved', 'Your order was approved.');
        Audit::notice($notSubscribed->id, 'Order approved', 'Your order was approved.');

        Notification::assertSentTo($subscribed, AccountNoticePush::class, function (AccountNoticePush $push, array $channels): bool {
            return $channels === [WebPushChannel::class] && $push->title === 'Order approved' && $push->body === 'Your order was approved.';
        });
        Notification::assertNotSentTo($notSubscribed, AccountNoticePush::class);
        $this->assertSame(2, DB::table('account_notices')->count());
    }

    public function test_staff_notices_are_pushed_only_to_subscribed_staff(): void
    {
        $this->enableWebPush();
        Notification::fake();
        $subscribed = User::factory()->staff('super_admin')->create(['status' => 'active']);
        $notSubscribed = User::factory()->staff('super_admin')->create(['status' => 'active']);
        $this->subscribe($subscribed);

        Audit::notifyAdminsAndStaff('finance', 'Withdrawal requested', 'A dealer asked for a withdrawal.');

        Notification::assertSentToTimes($subscribed, AccountNoticePush::class, 1);
        Notification::assertNotSentTo($notSubscribed, AccountNoticePush::class);
    }

    public function test_nothing_is_pushed_when_the_feature_is_off(): void
    {
        config(['webpush.vapid.public_key' => null, 'webpush.vapid.private_key' => null]);
        Notification::fake();
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($dealer);

        Audit::notice($dealer->id, 'Order approved', 'Your order was approved.');

        Notification::assertNothingSent();
        $this->assertSame(1, DB::table('account_notices')->where('user_id', $dealer->id)->count());
    }

    public function test_push_channel_is_used_only_when_the_user_has_a_subscription(): void
    {
        $withSubscription = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $withoutSubscription = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($withSubscription);
        $push = new AccountNoticePush('Title', 'Body');

        $this->assertSame([WebPushChannel::class], $push->via($withSubscription->fresh()));
        $this->assertSame([], $push->via($withoutSubscription->fresh()));
    }

    public function test_expired_subscription_is_removed_when_the_push_service_rejects_it(): void
    {
        $this->enableWebPush();
        Http::preventStrayRequests();
        Http::fake(['push.example.test/*' => Http::response('', 410)]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($dealer);

        $dealer->notifyNow(new AccountNoticePush('Order approved', 'Your order was approved.'));

        Http::assertSentCount(1);
        $this->assertFalse($dealer->pushSubscriptions()->exists());
    }

    public function test_delivered_push_keeps_the_subscription(): void
    {
        $this->enableWebPush();
        Http::preventStrayRequests();
        Http::fake(['push.example.test/*' => Http::response('', 201)]);
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->subscribe($dealer);

        $dealer->notifyNow(new AccountNoticePush('Order approved', 'Your order was approved.'));

        Http::assertSent(fn ($request): bool => $request->url() === self::Endpoint && $request->hasHeader('Authorization'));
        $this->assertTrue($dealer->pushSubscriptions()->exists());
    }
}
