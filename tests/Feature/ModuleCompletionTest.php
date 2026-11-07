<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionService;
use App\Domain\Finance\FinanceSettings;
use App\Domain\Finance\ReportData;
use App\Domain\Orders\OrderService;
use App\Domain\Staff\StaffPermissions;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ModuleCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->enableFinancialPosting();
        $this->travelTo(CarbonImmutable::parse('2026-09-15T06:00:00Z'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    private function purchase(User $buyer, string $cash = '100.00', bool $posted = false, string $category = 'Wellness'): Order
    {
        $this->seed(CommissionRuleSeeder::class);
        $product = Product::factory()->create(['mrp' => Money::decimal(Money::cents($cash) * 2), 'dealer_price' => $cash, 'category' => $category]);
        $order = app(OrderService::class)->create($buyer, ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'idempotency_key' => (string) Str::uuid()]);
        $order->update(['status' => $posted ? 'delivered' : 'approved', 'paid' => $posted ? $cash : '0.00']);
        if ($posted) {
            app(CommissionService::class)->processOrder($order);
        }

        return $order->refresh();
    }

    /** @return array<string, string> */
    private function note(string $kind = 'credit', string $amount = '20.00'): array
    {
        return ['kind' => $kind, 'amount' => $amount, 'reference' => 'NOTE-'.Str::uuid(), 'request_key' => (string) Str::uuid(), 'reason' => 'Approved commercial allowance'];
    }

    public function test_due_adjustments_are_retired_now_that_goods_are_never_given_on_credit(): void
    {
        $order = $this->purchase(User::factory()->dealer()->create());
        $finance = User::factory()->staff('finance')->create();

        $this->actingAs($finance)->postJson('/api/v1/operations/orders/'.$order->id.'/due-adjustments', $this->note())->assertNotFound();
        $this->postJson('/api/v1/operations/due-adjustments/1/review', ['decision' => 'approved', 'reason' => 'Old review link'])->assertNotFound();
        $this->get('/operations/dues-adjustments')->assertNotFound();
        $this->assertDatabaseCount('due_adjustments', 0);
    }

    public function test_configured_withdrawal_minimum_applies_to_new_requests_and_preserves_existing_replays(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $buyer = User::factory()->dealer()->create();
        $this->purchase($buyer, '20000.00', true);
        $payload = ['amount' => '250.00', 'channel' => 'Bank', 'destination' => 'Verified test destination', 'idempotency_key' => (string) Str::uuid()];
        $id = $this->actingAs($buyer)->postJson('/api/v1/withdrawals', $payload)->assertCreated()->json('data.id');
        $url = '/api/v1/operations/settings/withdrawal-minimum';
        $settings = ['amount' => '500.00', 'version' => 0, 'reason' => 'Approved new withdrawal minimum'];
        $this->postJson($url, $settings)->assertNotFound();

        $this->actingAs($finance)->postJson($url, $settings)->assertOk();

        $this->postJson($url, $settings)->assertUnprocessable();
        $this->assertDatabaseHas('withdrawals', ['id' => $id, 'minimum_snapshot' => '200.00']);
        $this->actingAs($buyer)->postJson('/api/v1/withdrawals', $payload)->assertCreated()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/withdrawals', [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->postJson('/api/v1/withdrawals', [...$payload, 'amount' => '500.00', 'idempotency_key' => (string) Str::uuid()])->assertCreated();
        $this->get('/workspace')->assertOk()->assertInertia(fn ($page) => $page->where('withdrawalMinimum', '500.00'));
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'withdrawal.minimum_changed']);
    }

    public function test_custom_read_only_role_cannot_write_through_commands_or_api(): void
    {
        $admin = User::factory()->staff()->create();
        $payload = ['key' => 'custom_catalog_viewer', 'name' => 'Catalog viewer', 'version' => 0,
            'permissions' => ['catalog' => ['view' => true, 'create' => false, 'edit' => false, 'approve' => false]], 'reason' => 'Limit support to viewing products'];
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/roles', $payload)->assertOk();
        $this->postJson('/api/v1/operations/staff/roles', $payload)->assertUnprocessable();
        $viewer = User::factory()->create(['role' => 'custom_catalog_viewer']);
        $product = Product::factory()->create();
        $edit = ['description' => 'Unauthorized change', 'version' => '1', 'reason' => 'Attempt to change a product'];

        $this->actingAs($viewer)->get('/company/products/'.$product->id)->assertOk();

        $this->patchJson('/commands/products/'.$product->id, $edit)->assertForbidden();
        $this->postJson('/api/v1/operations/payment-accounts', [])->assertForbidden();
        $this->getJson('/api/v1/operations/views/access-management')->assertForbidden();
        $this->assertSame('Test-only product information.', $product->fresh()->description);
        $this->assertDatabaseCount('payment_accounts', 0);
    }

    public function test_individual_permissions_are_versioned_and_sr_cannot_be_escalated(): void
    {
        $admin = User::factory()->staff()->create();
        $employee = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['finance']]]);
        $sr = User::factory()->staff('sr')->create();
        $permissions = app(StaffPermissions::class);
        $matrix = ['finance' => ['view' => true, 'create' => true, 'edit' => false, 'approve' => false]];
        $payload = ['staff_id' => $employee->id, 'version' => $permissions->version($employee), 'permissions' => $matrix, 'reason' => 'Separate preparation from approval'];
        $this->actingAs($admin)->postJson('/api/v1/operations/staff/action-permissions', $payload)->assertOk();
        $this->patchJson('/commands/accounts/'.$employee->id, ['permissions' => [], 'reason' => 'Attempt conflicting legacy permission edit'])->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->patchJson('/commands/accounts/'.$employee->id, ['name' => 'Finance preparer', 'reason' => 'Correct staff name while retaining action permissions'])->assertRedirect();
        $this->assertSame($matrix, $employee->fresh()->profile['action_permissions']);
        $this->postJson('/api/v1/operations/staff/action-permissions', $payload)->assertUnprocessable();
        $this->postJson('/api/v1/operations/staff/action-permissions', [...$payload, 'staff_id' => $sr->id, 'version' => $permissions->version($sr)])->assertUnprocessable();
        $this->postJson('/api/v1/operations/staff/roles', ['key' => 'custom_bad', 'name' => 'Bad role', 'version' => 0, 'permissions' => ['unknown' => ['view' => true]], 'reason' => 'Attempt unknown capability'])->assertUnprocessable();
        $order = $this->purchase(User::factory()->dealer()->create());

        // Due adjustments are retired (no goods on credit), so even a permitted employee finds no such action.
        $this->actingAs($employee->fresh())->postJson('/api/v1/operations/orders/'.$order->id.'/due-adjustments', $this->note())->assertNotFound();
        $this->postJson('/api/v1/finance/salary-preview', ['monthly_purchases' => '10000.00'])->assertOk()->assertJsonPath('data.salary', '300.00')->assertJsonPath('data.posts_earnings', false);
        $this->assertDatabaseCount('commission_batches', 0);

        $this->postJson('/api/v1/operations/settings/withdrawal-minimum', ['amount' => '500.00', 'version' => 0, 'reason' => 'Unauthorized setting change'])->assertForbidden();
        $this->postJson('/api/v1/finance/settlements', ['period_month' => '2026-08', 'kind' => 'salary'])->assertForbidden();
        $this->postJson('/api/v1/operations/staff/roles', [])->assertForbidden();
    }

    public function test_financial_actions_retain_the_staff_operator_separately_from_the_buyer(): void
    {
        $buyer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->purchase($buyer, '10000.00', true);
        $this->travelTo(CarbonImmutable::parse('2026-10-01T06:00:00Z'));

        $this->actingAs($finance)->post('/commands/commissions/settle-salary', ['period_month' => '2026-09', 'request_key' => (string) Str::uuid()])->assertRedirect();
        $this->postJson('/api/v1/finance/settlements', ['period_month' => '2026-09', 'kind' => 'royalty', 'request_key' => (string) Str::uuid()])->assertOk();
        $this->postJson('/api/v1/finance/orders/'.$order->id.'/commission-refunds', ['refund_amount' => '100.00', 'reference' => 'AUDITED-REFUND', 'reason' => 'Independently reviewed source adjustment', 'request_key' => (string) Str::uuid()])->assertOk();

        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commissions.salary_pool_settled']);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commissions.royalty_pool_settled']);
        $event = DB::table('audit_events')->where('action', 'commissions.reversed')->first();
        $this->assertSame($finance->id, $event->actor_id);
        $this->assertSame($buyer->id, json_decode($event->metadata, true)['buyer_id']);
    }

    public function test_rank_award_report_includes_actual_funded_awards_and_source_reversals(): void
    {
        // This checks the manual claim by finance, so the automatic reward at promotion is switched off.
        app(FinanceSettings::class)->saveAutoApproval(User::factory()->create(['role' => 'super_admin']), false,
            ['salary' => true, 'royalty' => true, 'rank_incentive' => false], 0, 'Rank rewards checked by hand in this test');
        $buyer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $order = $this->purchase($buyer, '250000.00', true);
        $this->actingAs($finance)->post('/commands/commissions/claim-incentive/'.$buyer->id, ['request_key' => (string) Str::uuid()])->assertRedirect();
        $this->postJson('/api/v1/finance/orders/'.$order->id.'/commission-refunds', ['refund_amount' => '50000.00', 'reference' => 'RANK-REFUND', 'reason' => 'Reduce source after rank award', 'request_key' => (string) Str::uuid()])->assertOk();

        $report = app(ReportData::class)->query($finance, 'rank-incentives', '2026-09-01', '2026-09-30')['query']->get();

        $this->assertCount(1, $report);
        $this->assertSame('5000.00', $report[0]->amount);
        $this->assertSame('1000.00', $report[0]->reversed_amount);
        $this->assertSame('4000.00', $report[0]->net_award);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $finance->id, 'action' => 'commissions.rank_incentive_paid', 'subject' => (string) $buyer->id]);
        $response = $this->get('/commands/operations/reports?type=rank-incentives&format=xlsx&from=2026-09-01&to=2026-09-30')->assertOk();
        $file = $response->baseResponse->getFile()->getPathname();
        $zip = new \ZipArchive;
        $zip->open($file);
        // Money cells are numeric so the total can be summed in Excel.
        $this->assertStringContainsString('<v>4000</v>', $zip->getFromName('xl/worksheets/sheet1.xml'));
        $zip->close();
        unlink($file);
    }

    public function test_grouped_sales_keep_captured_categories_and_exclude_unfulfilled_orders(): void
    {
        $buyer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        $one = $this->purchase($buyer, '100.00', true, 'Herbal');
        $this->purchase($buyer, '200.00', true, 'Herbal');
        $this->purchase($buyer, '50.00', false, 'Herbal');
        Product::whereKey($one->lines->first()->product_id)->update(['category' => 'Renamed later']);

        $rows = app(ReportData::class)->query($finance, 'category-summary', '2026-09-15', '2026-09-15')['query']->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Herbal', $rows[0]->category);
        $this->assertSame('300.00', $rows[0]->gross_sales);
        $this->assertSame(2, $rows[0]->order_count);
        $products = app(ReportData::class)->query($finance, 'product-summary', '2026-09-15', '2026-09-15')['query']->get();
        $this->assertCount(2, $products);
        $this->actingAs($buyer)->getJson('/api/v1/operations/reports?type=category-summary&format=xlsx&from=2026-09-15&to=2026-09-15')->assertForbidden();
    }

    public function test_rank_history_report_filters_each_achievement_date_in_dhaka(): void
    {
        $buyer = User::factory()->dealer()->create();
        $finance = User::factory()->staff('finance')->create();
        DB::table('dealer_ranks')->insert(['user_id' => $buyer->id, 'current_rank' => 'am', 'achieved_at' => '2026-09-15 06:00:00',
            'qualification_history' => json_encode([['rank' => 'mo', 'achieved_at' => '2026-09-01T06:00:00Z'], ['rank' => 'am', 'achieved_at' => '2026-09-14T19:00:00Z']]), 'created_at' => now(), 'updated_at' => now()]);

        $rows = app(ReportData::class)->query($finance, 'rank-achievements', '2026-09-15', '2026-09-15')['query']->get();

        $this->assertCount(1, $rows);
        $this->assertSame('am', $rows[0]->rank);
    }

    public function test_product_video_supports_vimeo_and_safe_direct_urls_and_clears_previous_source(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $product = Product::factory()->create();
        $url = '/commands/products/'.$product->id;
        $this->actingAs($editor)->patch($url, ['video_source' => 'vimeo', 'vimeo_url' => 'https://vimeo.com/123456789/abc123def0', 'version' => '0', 'reason' => 'Publish approved Vimeo demonstration'])->assertSessionHasNoErrors();
        $this->assertSame('https://player.vimeo.com/video/123456789?dnt=1&h=abc123def0', $product->fresh()->media()['video_embed_url']);
        $this->patch($url, ['video_source' => 'url', 'external_video_url' => 'https://media.example.com/demo.mp4?version=1', 'version' => '1', 'reason' => 'Publish approved direct video'])->assertSessionHasNoErrors();
        $this->assertNull($product->fresh()->video_vimeo_id);
        $this->assertSame('https://media.example.com/demo.mp4?version=1', $product->fresh()->media()['video_url']);
        $this->patch($url, ['video_source' => 'url', 'external_video_url' => 'https://127.0.0.1/secret.mp4', 'version' => '2', 'reason' => 'Attempt unsafe local video'])->assertSessionHasErrors('external_video_url');
        $this->patch($url, ['video_source' => 'vimeo', 'vimeo_url' => 'https://vimeo.com.evil.example/123456789', 'version' => '2', 'reason' => 'Attempt unrelated iframe origin'])->assertSessionHasErrors('vimeo_url');
        $this->assertSame(2, $product->fresh()->registry_version);
    }

    public function test_homepage_video_requires_a_published_video_and_hides_unpublished_products(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $product = Product::factory()->create(['video_source' => 'youtube', 'video_youtube_id' => 'dQw4w9WgXcQ']);
        $payload = ['enabled' => true, 'product_id' => $product->id, 'title' => 'Approved product demonstration', 'description' => '<script>unsafe()</script>', 'version' => 0, 'reason' => 'Publish approved homepage demonstration'];
        $url = '/api/v1/operations/marketing/homepage-video';
        $this->actingAs(User::factory()->dealer()->create())->postJson($url, $payload)->assertNotFound();

        $this->actingAs($editor)->postJson($url, $payload)->assertOk();

        $this->postJson($url, $payload)->assertUnprocessable();
        $this->get('/')->assertOk()->assertSee('Approved product demonstration')->assertSee('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ', false)->assertDontSee('<script>unsafe()</script>', false);
        $product->update(['active' => false]);
        $this->get('/')->assertOk()->assertDontSee('Approved product demonstration');
        $this->postJson($url, [...$payload, 'version' => 1])->assertUnprocessable();
    }
}
