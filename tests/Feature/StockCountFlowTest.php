<?php

namespace Tests\Feature;

use App\Domain\Inventory\BranchOperations;
use App\Domain\Inventory\StockCountService;
use App\Domain\Inventory\StockService;
use App\Models\BranchProductStock;
use App\Models\CompanyBranch;
use App\Models\Product;
use App\Models\StockCountLine;
use App\Models\StockCountSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StockCountFlowTest extends TestCase
{
    use RefreshDatabase;

    private CompanyBranch $branch;

    private User $counter;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->branch = CompanyBranch::factory()->create();
        $this->counter = User::factory()->staff('inventory')->create();
        $this->approver = User::factory()->staff('inventory')->create();
    }

    private function productHolding(int $quantity, ?CompanyBranch $branch = null): Product
    {
        $product = Product::factory()->create(['stock' => $quantity]);
        BranchProductStock::query()->create([
            'branch_id' => ($branch ?? $this->branch)->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
        ]);

        return $product;
    }

    private function startCount(?User $actor = null, ?CompanyBranch $branch = null): StockCountSession
    {
        $this->actingAs($actor ?? $this->counter)->onPortal('manage')
            ->post('/commands/stock-counts', ['branch_id' => ($branch ?? $this->branch)->id])
            ->assertSessionHasNoErrors();

        return StockCountSession::query()->latest('id')->firstOrFail();
    }

    private function lineFor(StockCountSession $session, Product $product): StockCountLine
    {
        return StockCountLine::query()
            ->where('stock_count_session_id', $session->id)
            ->where('product_id', $product->id)
            ->firstOrFail();
    }

    /**
     * @param  array<int, int|null>  $counts  Counted quantity by product id.
     */
    private function enterCounts(StockCountSession $session, array $counts, ?User $actor = null, string $portal = 'manage'): TestResponse
    {
        $lines = [];
        foreach ($counts as $productId => $counted) {
            $lines[] = [
                'line_id' => StockCountLine::query()
                    ->where('stock_count_session_id', $session->id)
                    ->where('product_id', $productId)
                    ->value('id'),
                'counted_quantity' => $counted,
            ];
        }

        return $this->actingAs($actor ?? $this->counter)->onPortal($portal)
            ->post("/commands/stock-counts/{$session->id}/lines", ['lines' => $lines]);
    }

    private function submit(StockCountSession $session, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->counter)->onPortal('manage')
            ->post("/commands/stock-counts/{$session->id}/submit");
    }

    private function approve(StockCountSession $session, ?User $actor = null): TestResponse
    {
        return $this->actingAs($actor ?? $this->approver)->onPortal('manage')
            ->post("/commands/stock-counts/{$session->id}/approve");
    }

    private function branchStock(Product $product, ?CompanyBranch $branch = null): int
    {
        return (int) BranchProductStock::query()
            ->where('branch_id', ($branch ?? $this->branch)->id)
            ->where('product_id', $product->id)
            ->value('quantity');
    }

    public function test_approved_variances_post_as_stock_adjustments_with_an_audit_entry(): void
    {
        $short = $this->productHolding(10);
        $over = $this->productHolding(5);
        $exact = $this->productHolding(7);
        $session = $this->startCount();
        $this->enterCounts($session, [$short->id => 8, $over->id => 9, $exact->id => 7])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->assertSame(10, $this->branchStock($short));

        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertSame(StockCountSession::StatusApproved, $session->fresh()->status);
        $this->assertSame($this->approver->id, (int) $session->fresh()->decided_by);
        $this->assertSame(8, $this->branchStock($short));
        $this->assertSame(8, $short->fresh()->stock);
        $this->assertSame(9, $this->branchStock($over));
        $this->assertSame(9, $over->fresh()->stock);
        $this->assertSame(7, $this->branchStock($exact));
        $this->assertDatabaseHas('stock_movements', ['product_id' => $short->id, 'branch_id' => $this->branch->id, 'quantity' => -2, 'kind' => 'product_out', 'reason' => "correction: Stock count #{$session->id}", 'actor_id' => $this->approver->id]);
        $this->assertDatabaseHas('stock_movements', ['product_id' => $over->id, 'quantity' => 4, 'kind' => 'product_in']);
        $this->assertDatabaseMissing('stock_movements', ['product_id' => $exact->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'stock_count.approved', 'subject' => (string) $session->id, 'actor_id' => $this->approver->id]);
    }

    public function test_the_person_who_counted_cannot_approve(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->approve($session, $this->counter)->assertSessionHasErrors('count');

        $this->assertSame(StockCountSession::StatusSubmitted, $session->fresh()->status);
        $this->assertSame(10, $this->branchStock($product));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_staff_who_helped_with_the_count_cannot_approve_it_but_the_owner_who_helped_can(): void
    {
        $product = $this->productHolding(10);
        $other = $this->productHolding(10);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $starter = $this->counter;
        $session = $this->startCount($starter);
        $this->enterCounts($session, [$product->id => 8], $this->approver)->assertSessionHasNoErrors();
        $this->enterCounts($session, [$other->id => 9], $owner, 'sadmin')->assertSessionHasNoErrors();
        $this->assertSame($owner->id, (int) $this->lineFor($session, $other)->counted_by);
        $submitter = User::factory()->staff('inventory')->create();
        $this->submit($session, $submitter)->assertSessionHasNoErrors();

        foreach ([$starter, $this->approver, $submitter] as $person) {
            $this->approve($session, $person)->assertSessionHasErrors('count');
        }
        $this->assertSame(10, $this->branchStock($product));

        $this->actingAs($owner)->onPortal('sadmin')->post("/commands/stock-counts/{$session->id}/approve")
            ->assertSessionHasNoErrors();

        $this->assertSame(8, $this->branchStock($product));
    }

    public function test_goods_moved_after_the_count_are_not_overwritten(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();
        app(StockService::class)->adjust($this->counter, [
            'product_id' => $product->id,
            'branch_id' => $this->branch->id,
            'kind' => BranchOperations::KindProductIn,
            'quantity' => 5,
            'reason_code' => 'supplier_receipt',
            'reason' => 'Arrived during the count',
            'reference' => (string) Str::uuid(),
        ]);

        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertSame(13, $this->branchStock($product));
        $this->assertSame(13, $product->fresh()->stock);
    }

    public function test_lines_nobody_counted_are_left_alone(): void
    {
        $counted = $this->productHolding(10);
        $skipped = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$counted->id => 9])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertSame(10, $this->branchStock($skipped));
    }

    public function test_saving_the_same_number_again_keeps_the_original_figures(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->productStockChangesTo($product, 15);

        $this->enterCounts($session, [$product->id => 8], $this->approver)->assertSessionHasNoErrors();

        $line = $this->lineFor($session, $product);
        $this->assertSame(10, $line->expected_quantity);
        $this->assertSame($this->counter->id, (int) $line->counted_by);
    }

    private function productStockChangesTo(Product $product, int $quantity): void
    {
        BranchProductStock::query()->where('branch_id', $this->branch->id)->where('product_id', $product->id)->update(['quantity' => $quantity]);
        $product->forceFill(['stock' => $quantity])->save();
    }

    public function test_a_product_with_only_a_company_total_is_counted_against_that_total(): void
    {
        $legacy = Product::factory()->create(['stock' => 20]);
        $session = $this->startCount();

        $this->enterCounts($session, [$legacy->id => 18])->assertSessionHasNoErrors();
        $this->assertSame(20, $this->lineFor($session, $legacy)->expected_quantity);
        $this->submit($session)->assertSessionHasNoErrors();
        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertSame(18, $this->branchStock($legacy));
        $this->assertSame(18, $legacy->fresh()->stock);
    }

    public function test_if_one_variance_cannot_post_none_of_them_do(): void
    {
        $fine = $this->productHolding(10);
        $tooShort = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$fine->id => 9, $tooShort->id => 0])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();
        $this->productStockChangesTo($tooShort, 3);

        $this->approve($session)->assertSessionHasErrors('quantity');

        $this->assertSame(StockCountSession::StatusSubmitted, $session->fresh()->status);
        $this->assertSame(10, $this->branchStock($fine));
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_approving_twice_posts_once(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->approve($session)->assertSessionHasNoErrors();
        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertSame(8, $this->branchStock($product));
        $this->assertDatabaseCount('stock_movements', 1);
    }

    public function test_a_rejected_count_changes_no_stock_and_frees_the_branch(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->actingAs($this->counter)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/reject", ['note' => 'Mine'])
            ->assertSessionHasErrors('count');
        $this->actingAs($this->approver)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/reject", ['note' => 'Count again'])
            ->assertSessionHasNoErrors();

        $this->assertSame(StockCountSession::StatusRejected, $session->fresh()->status);
        $this->assertSame(10, $this->branchStock($product));
        $this->assertDatabaseHas('audit_events', ['action' => 'stock_count.rejected', 'subject' => (string) $session->id]);
        $this->startCount();
        $this->assertDatabaseCount('stock_count_sessions', 2);
    }

    public function test_a_branch_can_have_only_one_open_count(): void
    {
        $this->startCount();

        $this->actingAs($this->approver)->onPortal('manage')
            ->post('/commands/stock-counts', ['branch_id' => $this->branch->id])
            ->assertSessionHasErrors('branch_id');

        $this->assertDatabaseCount('stock_count_sessions', 1);
    }

    public function test_an_unsubmitted_count_can_be_cancelled(): void
    {
        $session = $this->startCount();

        $this->actingAs($this->counter)->onPortal('manage')->post("/commands/stock-counts/{$session->id}/cancel")
            ->assertSessionHasNoErrors();

        $this->assertSame(StockCountSession::StatusCancelled, $session->fresh()->status);
    }

    public function test_counts_cannot_change_once_submitted_and_empty_counts_cannot_be_submitted(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();

        $this->submit($session)->assertSessionHasErrors('count');

        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->enterCounts($session, [$product->id => 3])->assertSessionHasErrors('count');
        $this->assertSame(8, $this->lineFor($session, $product)->counted_quantity);
    }

    public function test_a_line_from_another_count_is_refused(): void
    {
        $product = $this->productHolding(10);
        $other = CompanyBranch::factory()->create();
        $here = $this->startCount();
        $there = $this->startCount($this->counter, $other);

        $this->actingAs($this->counter)->onPortal('manage')->post("/commands/stock-counts/{$here->id}/lines", [
            'lines' => [['line_id' => $this->lineFor($there, $product)->id, 'counted_quantity' => 1]],
        ])->assertSessionHasErrors('lines');
    }

    public function test_the_page_shows_variances_and_who_may_decide(): void
    {
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->actingAs($this->counter)->onPortal('manage')->get('/stock-counts')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'stock-counts')
                ->where('data.stock_counts.selected.id', $session->id)
                ->where('data.stock_counts.selected.can_decide', false)
                ->where('data.stock_counts.selected.lines.0.expected', 10)
                ->where('data.stock_counts.selected.lines.0.counted', 8)
                ->where('data.stock_counts.selected.lines.0.variance', -2)
                ->where('data.stock_counts.sessions.data.0.variance_lines', 1));

        $this->actingAs($this->approver)->onPortal('manage')->get('/stock-counts')
            ->assertInertia(fn (Assert $page) => $page->where('data.stock_counts.selected.can_decide', true));
    }

    public function test_the_list_follows_the_branch_being_viewed(): void
    {
        $other = CompanyBranch::factory()->create();
        $this->productHolding(10);
        $here = $this->startCount();
        $there = $this->startCount($this->counter, $other);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->get('/stock-counts?branch_id='.$this->branch->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.stock_counts.sessions.data', 1)
                ->where('data.stock_counts.sessions.data.0.id', $here->id)
                ->where('data.stock_counts.selected.id', $here->id));

        $this->actingAs($owner)->onPortal('sadmin')->get('/stock-counts?branch_id='.$this->branch->id.'&session='.$there->id)
            ->assertInertia(fn (Assert $page) => $page->where('data.stock_counts.selected', null));
    }

    public function test_staff_without_the_inventory_permission_are_refused(): void
    {
        $finance = User::factory()->staff('finance')->create();
        $product = $this->productHolding(10);
        $session = $this->startCount();
        $this->enterCounts($session, [$product->id => 8])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();

        $this->actingAs($finance)->onPortal('manage')->get('/stock-counts')->assertForbidden();
        $this->actingAs($finance)->onPortal('manage')->post('/commands/stock-counts', ['branch_id' => $this->branch->id])->assertForbidden();
        $this->approve($session, $finance)->assertForbidden();

        $this->assertSame(10, $this->branchStock($product));
    }

    public function test_staff_without_the_approve_action_can_count_but_not_approve(): void
    {
        $clerk = User::factory()->staff('inventory')->create(['profile' => ['action_permissions' => [
            'inventory' => ['view' => true, 'create' => true, 'edit' => true, 'approve' => false],
        ]]]);
        $product = $this->productHolding(10);
        $session = $this->startCount($clerk);
        $this->enterCounts($session, [$product->id => 8], $clerk)->assertSessionHasNoErrors();
        $this->submit($session, $clerk)->assertSessionHasNoErrors();
        $otherClerk = User::factory()->staff('inventory')->create(['profile' => $clerk->profile]);

        $this->approve($session, $otherClerk)->assertForbidden();

        $this->assertSame(10, $this->branchStock($product));
    }

    public function test_dealers_cannot_use_stock_counts(): void
    {
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/stock-counts', ['branch_id' => $this->branch->id])
            ->assertForbidden();

        $this->assertDatabaseCount('stock_count_sessions', 0);
    }

    public function test_overdue_branches_are_reminded_by_portal_notice_to_inventory_staff_only(): void
    {
        $overdue = CompanyBranch::factory()->create(['created_at' => now()->subDays(StockCountService::OverdueDays + 1)]);
        $this->productHolding(4, $overdue);
        $finance = User::factory()->staff('finance')->create();

        $this->artisan('stock:count-reminders')->assertSuccessful();

        $this->assertDatabaseHas('account_notices', ['user_id' => $this->counter->id, 'title' => __('purchasing.notice_count_overdue_title', [], $this->counter->preferredLocale())]);
        $this->assertDatabaseMissing('account_notices', ['user_id' => $finance->id]);
        $notice = (string) DB::table('account_notices')->where('user_id', $this->counter->id)->value('body');
        $this->assertStringContainsString($overdue->code, $notice);
    }

    public function test_a_recently_counted_branch_is_not_reminded(): void
    {
        $branch = CompanyBranch::factory()->create(['created_at' => now()->subDays(StockCountService::OverdueDays + 40)]);
        $product = $this->productHolding(4, $branch);
        $session = $this->startCount($this->counter, $branch);
        $this->enterCounts($session, [$product->id => 4])->assertSessionHasNoErrors();
        $this->submit($session)->assertSessionHasNoErrors();
        $this->approve($session)->assertSessionHasNoErrors();

        $this->assertFalse(app(StockCountService::class)->overdueBranches()->contains('id', $branch->id));
    }

    public function test_a_branch_past_the_limit_since_its_last_approved_count_is_overdue_again(): void
    {
        $branch = CompanyBranch::factory()->create(['created_at' => now()->subDays(StockCountService::OverdueDays + 40)]);
        $this->productHolding(4, $branch);
        StockCountSession::query()->create([
            'branch_id' => $branch->id,
            'status' => StockCountSession::StatusApproved,
            'started_by' => $this->counter->id,
            'decided_by' => $this->approver->id,
            'decided_at' => now()->subDays(StockCountService::OverdueDays + 1),
        ]);

        $this->assertTrue(app(StockCountService::class)->overdueBranches()->contains('id', $branch->id));
    }

    public function test_a_branch_holding_no_goods_is_never_overdue(): void
    {
        $empty = CompanyBranch::factory()->create(['created_at' => now()->subDays(StockCountService::OverdueDays + 1)]);

        $this->assertFalse(app(StockCountService::class)->overdueBranches()->contains('id', $empty->id));
    }
}
