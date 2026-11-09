<?php

namespace Tests\Feature;

use App\Domain\Content\SalesKit;
use App\Domain\Content\TrainingLibrary;
use App\Domain\Orders\OrderService;
use App\Domain\Orders\PartnerContests;
use App\Models\DatedScheme;
use App\Models\Order;
use App\Models\PartnerContest;
use App\Models\Product;
use App\Models\SalesKitItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Editing schemes, contests and sales kit files: the owner's reason is kept, and nothing that
 * has already happened (free goods on an order, contest results) changes because of an edit.
 */
class EditSchemesContestsKitTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::fake();
        Storage::fake(TrainingLibrary::diskName());
        Carbon::setTestNow('2026-11-10 06:00:00');
        $this->owner = User::factory()->create(['role' => 'md', 'status' => 'active', 'locale' => 'en']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function wrongSchemeEdits(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'no start' => [['starts_on' => ''], 'starts_on'],
            'no end' => [['ends_on' => ''], 'ends_on'],
            'end before start' => [['ends_on' => '2026-10-31'], 'ends_on'],
            'a date that does not exist' => [['starts_on' => '2026-02-31'], 'starts_on'],
            'no products' => [['product_ids' => []], 'product_ids'],
            'no minimum' => [['min_quantity' => ''], 'min_quantity'],
            'minimum of zero' => [['min_quantity' => 0], 'min_quantity'],
            'unknown benefit' => [['benefit_type' => 'cash'], 'benefit_type'],
            'no benefit text' => [['benefit_text' => ''], 'benefit_text'],
            'free quantity with no product' => [['free_quantity' => 2], 'free_product_id'],
            'no reason' => [['reason' => ''], 'reason'],
            'reason too short' => [['reason' => 'abc'], 'reason'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('wrongSchemeEdits')]
    public function test_a_wrong_scheme_edit_is_refused_and_nothing_changes(array $change, string $field): void
    {
        $scheme = $this->scheme();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'title' => 'Should not save', ...$change])
            ->assertSessionHasErrors($field);

        $this->assertSame('Winter offer', $scheme->fresh()->title);
        $this->assertDatabaseMissing('audit_events', ['action' => 'dated_scheme.updated']);
    }

    public function test_a_refused_scheme_edit_gives_its_reason_in_the_persons_language(): void
    {
        $scheme = $this->scheme();
        foreach (['bn' => 'কারণ দিতে হবে', 'en' => 'reason'] as $locale => $expected) {
            $person = User::factory()->create(['role' => 'md', 'status' => 'active', 'locale' => $locale]);

            $this->actingAs($person)->onPortal('sadmin')->from('/schemes')
                ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'reason' => ''])
                ->assertSessionHasErrors('reason');

            $this->assertStringContainsString($expected, session('errors')->first('reason'));
        }
    }

    public function test_only_owners_can_edit_a_scheme(): void
    {
        $scheme = $this->scheme();
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->patch('/commands/schemes/'.$scheme->id, $this->schemeEdit($scheme))->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/schemes/'.$scheme->id, $this->schemeEdit($scheme))->assertForbidden();

        $this->assertSame('Winter offer', $scheme->fresh()->title);
    }

    public function test_a_scheme_nobody_has_used_can_change_completely_and_partners_see_it(): void
    {
        $scheme = $this->scheme();
        $other = Product::factory()->create(['active' => true]);
        $free = Product::factory()->create(['active' => true]);
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')->patch('/commands/schemes/'.$scheme->id, [
            ...$this->schemeEdit($scheme),
            'title' => 'Winter offer, longer',
            'starts_on' => '2026-11-05',
            'ends_on' => '2026-12-15',
            'product_ids' => [$other->id],
            'min_quantity' => 6,
            'benefit_text' => 'Two boxes free with six',
            'free_product_id' => $free->id,
            'free_quantity' => 2,
            'reason' => 'Owner extended the winter offer',
        ])->assertRedirect('/schemes')->assertSessionHasNoErrors()->assertSessionHas('success');

        $scheme->refresh();
        $this->assertSame('2026-12-15', $scheme->ends_on->toDateString());
        $this->assertSame([$other->id], $scheme->product_ids);
        $this->assertSame((int) $free->id, (int) $scheme->free_product_id);

        $this->actingAs($dealer)->onPortal('dealer')->get('/purchase')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.running_schemes.0.title', 'Winter offer, longer')
                ->where('data.running_schemes.0.benefit_text', 'Two boxes free with six')
                ->where('data.running_schemes.0.min_quantity', 6)
                ->where('data.running_schemes.0.ends_on', '2026-12-15')
                ->where('data.running_schemes.0.product_ids', [$other->id]));
    }

    public function test_a_scheme_edit_is_in_the_audit_log_with_its_reason_and_what_changed(): void
    {
        $scheme = $this->scheme();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'title' => 'New name', 'reason' => 'Name had a spelling mistake'])
            ->assertSessionHasNoErrors();

        $event = DB::table('audit_events')->where('action', 'dated_scheme.updated')->sole();
        $metadata = json_decode((string) $event->metadata, true);
        $this->assertSame((int) $this->owner->id, (int) $event->actor_id);
        $this->assertSame((string) $scheme->id, $event->subject);
        $this->assertSame('Name had a spelling mistake', $metadata['reason']);
        $this->assertSame(['title'], $metadata['fields']);
        $this->assertSame('Winter offer', $metadata['before']['title']);
        $this->assertSame('New name', $metadata['after']['title']);
    }

    public function test_editing_a_used_scheme_never_changes_the_free_goods_or_money_on_its_orders(): void
    {
        [$scheme, $order, $paid, $free] = $this->usedScheme();
        $linesBefore = DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($line): array => (array) $line)->all();
        $totalBefore = (string) DB::table('orders')->where('id', $order->id)->value('total');

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'title' => 'Renamed offer', 'benefit_text' => 'New words', 'ends_on' => '2026-12-31'])
            ->assertSessionHasNoErrors();

        $scheme->refresh();
        $this->assertSame('Renamed offer', $scheme->title);
        $this->assertSame('New words', $scheme->benefit_text);
        $this->assertSame('2026-12-31', $scheme->ends_on->toDateString());

        $this->assertSame($linesBefore, DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get()->map(fn ($line): array => (array) $line)->all());
        $this->assertSame($totalBefore, (string) DB::table('orders')->where('id', $order->id)->value('total'));
        $this->assertSame('Winter offer', DB::table('order_lines')->where('order_id', $order->id)->where('is_free', true)->value('scheme_title'));
    }

    /** @return array<string, array{0: string, 1: mixed}> */
    public static function lockedSchemeTerms(): array
    {
        return [
            'start date' => ['starts_on', '2026-11-02'],
            'minimum' => ['min_quantity', 9],
            'benefit type' => ['benefit_type', 'discount'],
            'free quantity' => ['free_quantity', 5],
        ];
    }

    #[DataProvider('lockedSchemeTerms')]
    public function test_the_terms_of_a_used_scheme_are_locked(string $field, mixed $value): void
    {
        [$scheme] = $this->usedScheme();
        $before = $scheme->fresh()->toArray();
        $edit = [...$this->schemeEdit($scheme), $field => $value];
        if ($field === 'benefit_type') {
            $edit = [...$edit, 'free_product_id' => '', 'free_quantity' => ''];
        }

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, $edit)
            ->assertSessionHasErrors($field);

        $this->assertSame($before, $scheme->fresh()->toArray());
        $this->assertDatabaseMissing('audit_events', ['action' => 'dated_scheme.updated']);
    }

    public function test_the_products_and_free_product_of_a_used_scheme_are_locked(): void
    {
        [$scheme, , $paid, $free] = $this->usedScheme();
        $other = Product::factory()->create(['active' => true]);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'product_ids' => [$paid->id, $other->id]])
            ->assertSessionHasErrors('product_ids');
        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'free_product_id' => $other->id])
            ->assertSessionHasErrors('free_product_id');

        $this->assertSame([(int) $paid->id], array_map('intval', $scheme->fresh()->product_ids));
        $this->assertSame((int) $free->id, (int) $scheme->fresh()->free_product_id);
    }

    public function test_the_end_date_of_a_used_scheme_cannot_be_moved_before_its_last_order(): void
    {
        [$scheme] = $this->usedScheme();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'ends_on' => '2026-11-09'])
            ->assertSessionHasErrors('ends_on');
        $this->assertSame('2026-11-30', $scheme->fresh()->ends_on->toDateString());

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'ends_on' => '2026-11-10'])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-10', $scheme->fresh()->ends_on->toDateString());
    }

    public function test_the_owner_page_says_which_schemes_are_locked_and_why(): void
    {
        [$scheme] = $this->usedScheme();
        $unused = $this->scheme();

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/schemes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.schemes_admin.schemes', fn ($schemes): bool => collect($schemes)->firstWhere('id', $scheme->id)['orders_using'] === 1
                    && collect($schemes)->firstWhere('id', $scheme->id)['earliest_end'] === '2026-11-10'
                    && collect($schemes)->firstWhere('id', $unused->id)['orders_using'] === 0
                    && collect($schemes)->firstWhere('id', $unused->id)['earliest_end'] === null));
    }

    public function test_the_next_order_follows_an_edited_scheme(): void
    {
        $free = Product::factory()->create(['active' => true, 'stock' => 500]);
        $scheme = $this->scheme();
        $paid = Product::query()->findOrFail($scheme->product_ids[0]);
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'min_quantity' => 4, 'free_product_id' => $free->id, 'free_quantity' => 3])
            ->assertSessionHasNoErrors();

        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $paid->id, 'quantity' => 4]], 'idempotency_key' => (string) Str::uuid()]);

        $line = DB::table('order_lines')->where('order_id', $order->id)->where('is_free', true)->sole();
        $this->assertSame((int) $free->id, (int) $line->product_id);
        $this->assertSame(3, (int) $line->quantity);
    }

    public function test_a_scheme_keeps_a_product_that_was_later_hidden_but_cannot_gain_one(): void
    {
        $scheme = $this->scheme();
        $hidden = Product::query()->findOrFail($scheme->product_ids[0]);
        $hidden->update(['active' => false]);
        $alsoHidden = Product::factory()->create(['active' => false]);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme), 'title' => 'Still saves'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Still saves', $scheme->fresh()->title);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/schemes')
            ->patch('/commands/schemes/'.$scheme->id, [...$this->schemeEdit($scheme->fresh()), 'product_ids' => [$hidden->id, $alsoHidden->id]])
            ->assertSessionHasErrors('product_ids.1');

        $this->actingAs($this->owner)->onPortal('sadmin')->get('/schemes')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.schemes_admin.products', fn ($products): bool => collect($products)->firstWhere('id', $hidden->id)['active'] === false));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function wrongContestEdits(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'no start' => [['starts_on' => ''], 'starts_on'],
            'no end' => [['ends_on' => ''], 'ends_on'],
            'end before start' => [['ends_on' => '2026-11-20'], 'ends_on'],
            'unknown metric' => [['metric' => 'income'], 'metric'],
            'prize too long' => [['prize_text' => 'x'], 'prize_text'],
            'no reason' => [['reason' => ''], 'reason'],
            'reason too short' => [['reason' => 'no'], 'reason'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('wrongContestEdits')]
    public function test_a_wrong_contest_edit_is_refused(array $change, string $field): void
    {
        $contest = $this->contest(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        if ($field === 'prize_text') {
            $change['prize_text'] = str_repeat('x', 501);
        }

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
            ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), 'starts_on' => '2026-12-01', 'ends_on' => $change['ends_on'] ?? '2026-12-31', ...$change])
            ->assertSessionHasErrors($field);

        $this->assertSame('November push', $contest->fresh()->title);
        $this->assertDatabaseMissing('audit_events', ['action' => 'contest.updated']);
    }

    public function test_only_owners_can_edit_a_contest(): void
    {
        $contest = $this->contest();
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->patch('/commands/contests/'.$contest->id, $this->contestEdit($contest))->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->patch('/commands/contests/'.$contest->id, $this->contestEdit($contest))->assertForbidden();

        $this->assertSame('November push', $contest->fresh()->title);
    }

    public function test_a_contest_that_has_not_started_can_change_completely(): void
    {
        $contest = $this->contest(['starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')->patch('/commands/contests/'.$contest->id, [
            'title' => 'December push',
            'starts_on' => '2026-12-05',
            'ends_on' => '2026-12-25',
            'metric' => 'orders',
            'prize_text' => 'A bag',
            'reason' => 'Dates moved after the planning meeting',
        ])->assertRedirect('/contests')->assertSessionHasNoErrors()->assertSessionHas('success');

        $contest->refresh();
        $this->assertSame('December push', $contest->title);
        $this->assertSame('orders', $contest->metric);
        $this->assertSame('2026-12-05', $contest->starts_on->toDateString());

        $metadata = json_decode((string) DB::table('audit_events')->where('action', 'contest.updated')->sole()->metadata, true);
        $this->assertSame('Dates moved after the planning meeting', $metadata['reason']);
        $this->assertSame('purchases', $metadata['before']['metric']);
        $this->assertSame('orders', $metadata['after']['metric']);
    }

    public function test_a_running_contest_with_no_results_yet_can_still_change_what_it_counts(): void
    {
        $contest = $this->contest();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
            ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), 'metric' => 'orders', 'starts_on' => '2026-11-03'])
            ->assertSessionHasNoErrors();

        $this->assertSame('orders', $contest->fresh()->metric);
        $this->assertSame('2026-11-03', $contest->fresh()->starts_on->toDateString());
    }

    public function test_a_running_contest_with_results_keeps_them_and_partners_see_the_new_words(): void
    {
        $contest = $this->contest();
        $dealer = User::factory()->dealer()->create(['name' => 'Rahim']);
        $this->order($dealer, '300.00', '2026-11-05 06:00:00');
        $this->order($dealer, '200.00', '2026-11-09 06:00:00');

        foreach ([['metric', 'orders'], ['starts_on', '2026-11-06'], ['ends_on', '2026-11-09']] as [$field, $value]) {
            $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
                ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), $field => $value])
                ->assertSessionHasErrors($field);
        }
        $this->assertSame('purchases', $contest->fresh()->metric);
        $this->assertSame('500.00', $this->board($dealer, $contest)['standings'][0]['value']);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
            ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), 'title' => 'November big push', 'prize_text' => 'A better prize', 'ends_on' => '2026-11-10'])
            ->assertSessionHasNoErrors();

        $this->actingAs($dealer)->onPortal('dealer')->get('/contests')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.contests.contests.0.title', 'November big push')
                ->where('data.contests.contests.0.prize_text', 'A better prize')
                ->where('data.contests.contests.0.ends_on', '2026-11-10')
                ->where('data.contests.contests.0.standings.0.value', '500.00'));
    }

    public function test_an_ended_contest_with_results_keeps_its_final_standings(): void
    {
        $contest = $this->contest(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, '700.00', '2026-10-15 06:00:00');
        $this->order($dealer, '900.00', '2026-11-05 06:00:00');

        foreach ([['metric', 'orders'], ['starts_on', '2026-10-20'], ['ends_on', '2026-11-30']] as [$field, $value]) {
            $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
                ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), $field => $value])
                ->assertSessionHasErrors($field);
        }
        $this->assertSame('700.00', $this->board($dealer, $contest)['standings'][0]['value']);

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/contests')
            ->patch('/commands/contests/'.$contest->id, [...$this->contestEdit($contest), 'title' => 'October push', 'prize_text' => 'Helmet given'])
            ->assertSessionHasNoErrors();

        $this->assertSame('October push', $this->board($dealer, $contest)['title']);
        $this->assertSame('700.00', $this->board($dealer, $contest)['standings'][0]['value']);
    }

    public function test_the_owner_is_told_what_is_locked_on_a_contest_but_partners_are_not_sent_it(): void
    {
        $running = $this->contest();
        $ended = $this->contest(['title' => 'October', 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31']);
        $future = $this->contest(['title' => 'December', 'starts_on' => '2026-12-01', 'ends_on' => '2026-12-31']);
        $dealer = User::factory()->dealer()->create();
        $this->order($dealer, '100.00', '2026-11-05 06:00:00');
        $this->order($dealer, '100.00', '2026-10-05 06:00:00');

        $this->assertSame(['metric' => true, 'starts_on' => true, 'ends_on' => false], $this->board($this->owner, $running)['locked']);
        $this->assertSame('2026-11-10', $this->board($this->owner, $running)['earliest_end']);
        $this->assertSame(['metric' => true, 'starts_on' => true, 'ends_on' => true], $this->board($this->owner, $ended)['locked']);
        $this->assertSame(['metric' => false, 'starts_on' => false, 'ends_on' => false], $this->board($this->owner, $future)['locked']);
        $this->assertArrayNotHasKey('locked', $this->board($dealer, $running));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: string}> */
    public static function wrongKitEdits(): array
    {
        return [
            'no title' => [['title' => ''], 'title'],
            'unknown kind' => [['kind' => 'flyer'], 'kind'],
            'unknown language' => [['language' => 'fr'], 'language'],
            'no reason' => [['reason' => ''], 'reason'],
            'reason too short' => [['reason' => 'abc'], 'reason'],
        ];
    }

    /** @param array<string, mixed> $change */
    #[DataProvider('wrongKitEdits')]
    public function test_a_wrong_kit_edit_is_refused(array $change, string $field): void
    {
        $item = $this->kitItem();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/sales-kit')
            ->post('/commands/sales-kit/'.$item->id, [...$this->kitEdit(), 'caption' => 'Should not save', ...$change])
            ->assertSessionHasErrors($field);

        $this->assertSame('Share this', $item->fresh()->caption);
        $this->assertDatabaseMissing('audit_events', ['action' => 'sales_kit.updated']);
    }

    public function test_only_owners_can_edit_a_kit_item(): void
    {
        $item = $this->kitItem();
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/sales-kit/'.$item->id, $this->kitEdit())->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/sales-kit/'.$item->id, $this->kitEdit())->assertForbidden();

        $this->assertSame('Old poster', $item->fresh()->title);
    }

    public function test_a_kit_item_edit_without_a_file_keeps_the_file_and_partners_see_the_words(): void
    {
        $item = $this->kitItem();
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/sales-kit')
            ->post('/commands/sales-kit/'.$item->id, [...$this->kitEdit(), 'title' => 'New poster', 'kind' => 'social_post', 'language' => 'en', 'caption' => 'New words', 'reason' => 'Wording was out of date'])
            ->assertRedirect('/sales-kit')->assertSessionHasNoErrors()->assertSessionHas('success');

        Storage::disk(TrainingLibrary::diskName())->assertExists($item->file_path);
        $this->assertSame($item->file_path, $item->fresh()->file_path);

        $this->actingAs($dealer)->onPortal('dealer')->get('/sales-kit')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.sales_kit.items.0.title', 'New poster')
                ->where('data.sales_kit.items.0.kind', 'social_post')
                ->where('data.sales_kit.items.0.language', 'en')
                ->where('data.sales_kit.items.0.caption', 'New words'));

        $metadata = json_decode((string) DB::table('audit_events')->where('action', 'sales_kit.updated')->sole()->metadata, true);
        $this->assertSame('Wording was out of date', $metadata['reason']);
        $this->assertFalse($metadata['file_replaced']);
    }

    public function test_replacing_a_kit_file_swaps_it_for_partners_at_once(): void
    {
        $item = $this->kitItem();
        $oldPath = $item->file_path;
        $dealer = User::factory()->dealer()->create();
        $oldUrl = app(SalesKit::class)->forViewer($dealer)['items'][0]['view_url'];
        Carbon::setTestNow('2026-11-10 07:00:00');

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/sales-kit')
            ->post('/commands/sales-kit/'.$item->id, [...$this->kitEdit(), 'file' => UploadedFile::fake()->image('new-poster.png', 20, 20)])
            ->assertSessionHasNoErrors();

        $item->refresh();
        $this->assertNotSame($oldPath, $item->file_path);
        $this->assertSame('new-poster.png', $item->file_name);
        $this->assertSame('image/png', $item->mime);
        Storage::disk(TrainingLibrary::diskName())->assertMissing($oldPath);
        Storage::disk(TrainingLibrary::diskName())->assertExists($item->file_path);

        $this->actingAs($dealer)->onPortal('dealer')->get('/sales-kit/'.$item->id.'/file')
            ->assertOk()->assertHeader('Content-Type', 'image/png');

        $newUrls = app(SalesKit::class)->forViewer($dealer)['items'][0];
        $this->assertNotSame($oldUrl, $newUrls['view_url'], 'A cached copy of the old file must not be shown.');
        $this->assertNotSame('/sales-kit/'.$item->id.'/file', $newUrls['download_url']);
        $this->assertTrue(json_decode((string) DB::table('audit_events')->where('action', 'sales_kit.updated')->sole()->metadata, true)['file_replaced']);
    }

    public function test_a_wrong_replacement_file_is_refused_and_the_old_file_stays(): void
    {
        $item = $this->kitItem();

        $this->actingAs($this->owner)->onPortal('sadmin')->from('/sales-kit')
            ->post('/commands/sales-kit/'.$item->id, [...$this->kitEdit(), 'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')])
            ->assertSessionHasErrors('file');
        $this->actingAs($this->owner)->onPortal('sadmin')->from('/sales-kit')
            ->post('/commands/sales-kit/'.$item->id, [...$this->kitEdit(), 'file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])
            ->assertSessionHasErrors('file');

        $this->assertSame('sales-kit/old.pdf', $item->fresh()->file_path);
        Storage::disk(TrainingLibrary::diskName())->assertExists('sales-kit/old.pdf');
    }

    public function test_the_edit_actions_are_labelled_in_the_activity_log_in_both_languages(): void
    {
        foreach (['dated_scheme.updated', 'contest.updated', 'sales_kit.updated'] as $action) {
            $key = 'portal.event_'.str_replace('.', '_', $action);
            foreach (['bn', 'en'] as $locale) {
                $this->assertNotSame($key, __($key, [], $locale), $action.' has no '.$locale.' label');
            }
        }
    }

    /** @param array<string, mixed> $overrides */
    private function scheme(array $overrides = []): DatedScheme
    {
        $product = Product::factory()->create(['active' => true, 'stock' => 500, 'dealer_price' => '100.00', 'mrp' => '200.00']);

        return DatedScheme::query()->create([
            'title' => 'Winter offer',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'product_ids' => [$product->id],
            'min_quantity' => 10,
            'benefit_type' => 'free_goods',
            'benefit_text' => 'One box free with ten',
            'created_by' => $this->owner->id,
            ...$overrides,
        ]);
    }

    /** @return array{0: DatedScheme, 1: Order, 2: Product, 3: Product} */
    private function usedScheme(): array
    {
        $free = Product::factory()->create(['active' => true, 'stock' => 500, 'dealer_price' => '60.00', 'mrp' => '90.00']);
        $scheme = $this->scheme(['free_product_id' => $free->id, 'free_quantity' => 2]);
        $paid = Product::query()->findOrFail($scheme->product_ids[0]);
        $dealer = User::factory()->dealer()->create();
        $order = app(OrderService::class)->create($dealer, ['items' => [['product_id' => $paid->id, 'quantity' => 10]], 'idempotency_key' => (string) Str::uuid()]);
        $this->assertSame(1, DB::table('order_lines')->where('order_id', $order->id)->where('dated_scheme_id', $scheme->id)->count());

        return [$scheme, $order, $paid, $free];
    }

    /** @return array<string, mixed> */
    private function schemeEdit(DatedScheme $scheme): array
    {
        return [
            'title' => $scheme->title,
            'starts_on' => $scheme->starts_on->toDateString(),
            'ends_on' => $scheme->ends_on->toDateString(),
            'product_ids' => $scheme->product_ids,
            'min_quantity' => $scheme->min_quantity,
            'benefit_type' => $scheme->benefit_type,
            'benefit_text' => $scheme->benefit_text,
            'free_product_id' => $scheme->free_product_id,
            'free_quantity' => $scheme->free_quantity,
            'reason' => 'Owner asked for this change',
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function contest(array $overrides = []): PartnerContest
    {
        return PartnerContest::query()->create([
            'title' => 'November push',
            'starts_on' => '2026-11-01',
            'ends_on' => '2026-11-30',
            'metric' => 'purchases',
            'prize_text' => 'A helmet',
            'created_by' => $this->owner->id,
            ...$overrides,
        ]);
    }

    /** @return array<string, mixed> */
    private function contestEdit(PartnerContest $contest): array
    {
        return [
            'title' => $contest->title,
            'starts_on' => $contest->starts_on->toDateString(),
            'ends_on' => $contest->ends_on->toDateString(),
            'metric' => $contest->metric,
            'prize_text' => $contest->prize_text,
            'reason' => 'Owner asked for this change',
        ];
    }

    /** @return array<string, mixed> */
    private function board(User $viewer, PartnerContest $contest): array
    {
        return collect(app(PartnerContests::class)->forViewer($viewer)['contests'])->firstWhere('id', (int) $contest->id);
    }

    private function order(User $buyer, string $total, string $at): Order
    {
        $order = Order::create([
            'number' => 'ORD-'.Str::upper(Str::random(8)),
            'user_id' => $buyer->id,
            'status' => 'approved',
            'total' => $total,
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', Str::random()),
        ]);
        DB::table('orders')->where('id', $order->id)->update(['created_at' => $at]);

        return $order;
    }

    private function kitItem(): SalesKitItem
    {
        Storage::disk(TrainingLibrary::diskName())->put('sales-kit/old.pdf', 'pdf');

        return SalesKitItem::query()->create(['title' => 'Old poster', 'kind' => 'poster', 'language' => 'bn', 'caption' => 'Share this', 'file_path' => 'sales-kit/old.pdf', 'file_name' => 'old.pdf', 'mime' => 'application/pdf', 'size' => 3, 'uploaded_by' => $this->owner->id]);
    }

    /** @return array<string, string> */
    private function kitEdit(): array
    {
        return ['title' => 'Old poster', 'kind' => 'poster', 'language' => 'bn', 'caption' => 'Share this', 'reason' => 'Owner asked for this change'];
    }
}
