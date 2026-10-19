<?php

namespace Tests\Feature;

use App\Domain\Finance\PaymentAccounts;
use App\Domain\Finance\PolicyDraftService;
use App\Domain\Notifications\AccountCreatedSms;
use App\Domain\Orders\OrderService;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProjectHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_collection_accounts_are_not_shared_with_content_only_staff(): void
    {
        $finance = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(PaymentAccounts::class)->save($finance, [
            'label' => 'Company bank',
            'channel' => 'Bank',
            'holder' => 'Madina',
            'account_number' => '123456789012',
            'details' => 'Private payment instructions',
            'collects_payments' => true,
            'pays_out' => false,
            'is_active' => true,
            'reason' => 'Initial company collection account for hardening tests.',
        ]);

        $editor = User::factory()->create([
            'role' => 'editor',
            'status' => 'active',
        ]);

        $this->actingAs($editor)->onPortal('manage')->get('/content')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('collectionAccounts', []));

        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/catalog')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('collectionAccounts', 1)
                ->where('collectionAccounts.0.account_number', '123456789012'));
    }

    public function test_account_created_sms_does_not_log_plaintext_passwords(): void
    {
        config(['madina.sms.driver' => 'log', 'madina.sms.log_channel' => 'stack']);
        $user = User::factory()->create(['role' => 'dealer', 'phone' => '01711112222', 'status' => 'active']);
        $logged = [];
        Log::listen(function ($message) use (&$logged): void {
            $logged[] = ['level' => $message->level, 'message' => $message->message, 'context' => $message->context];
        });

        $this->assertTrue(app(AccountCreatedSms::class)->send($user, 'SecretPass1'));

        $entry = collect($logged)->first(fn (array $row) => $row['message'] === 'account.created.sms');
        $this->assertNotNull($entry);
        $this->assertArrayNotHasKey('message', $entry['context']);
        $this->assertStringContainsString('[REDACTED]', (string) ($entry['context']['message_redacted'] ?? ''));
        $this->assertStringNotContainsString('SecretPass1', json_encode($entry['context']));
    }

    public function test_policy_draft_can_be_published_for_review(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $id = app(PolicyDraftService::class)->create($admin, [
            'name' => 'Sub-dealer schedule',
            'account_type' => 'sub_dealer',
            'components' => [['name' => 'referral', 'rate' => '10']],
            'notes' => 'Document purchase-value rates before go-live.',
        ]);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post("/commands/policies/{$id}/publish")
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('policy_drafts', ['id' => $id, 'status' => 'published', 'basis' => 'purchase_value']);
    }

    public function test_payment_claim_reference_is_unique_across_orders(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app(PaymentAccounts::class)->save($admin, [
            'label' => 'bKash collect',
            'channel' => 'bKash',
            'holder' => 'Madina',
            'account_number' => '01700000000',
            'details' => '',
            'collects_payments' => true,
            'pays_out' => false,
            'is_active' => true,
            'reason' => 'Collection account for claim uniqueness test.',
        ]);
        $accountId = (int) DB::table('payment_accounts')->value('id');

        $buyerA = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $buyerB = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $product = Product::factory()->create(['mrp' => '200.00', 'dealer_price' => '100.00', 'stock' => 50, 'active' => true]);

        $orderA = app(OrderService::class)->create($buyerA, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $orderB = app(OrderService::class)->create($buyerB, [
            'items' => [['product_id' => $product->id, 'quantity' => 1]],
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $this->actingAs($buyerA)->postJson('/api/v1/orders/'.$orderA->id.'/payments', [
            'channel' => 'bKash',
            'payment_account_id' => $accountId,
            'reference' => 'TRX-HARDEN-1',
            'amount' => '100.00',
        ])->assertCreated();

        $this->actingAs($buyerB)->postJson('/api/v1/orders/'.$orderB->id.'/payments', [
            'channel' => 'bKash',
            'payment_account_id' => $accountId,
            'reference' => 'TRX-HARDEN-1',
            'amount' => '100.00',
        ])->assertUnprocessable()->assertJsonValidationErrors(['reference']);
    }

    public function test_website_content_accepts_public_page_slugs(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/content', [
            'slug' => 'partnership',
            'title' => 'Partnership',
            'body' => 'Become an authorized Madina partner.',
            'published' => true,
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertDatabaseHas('content_pages', ['slug' => 'partnership', 'title' => 'Partnership', 'published' => true]);
    }
}
