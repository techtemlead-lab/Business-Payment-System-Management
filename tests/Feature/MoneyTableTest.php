<?php

namespace Tests\Feature;

use App\Support\MoneyTable;
use App\Support\MoneyTableFilter;
use App\Support\MoneyTableSpec;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class MoneyTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        CarbonImmutable::setTestNow('2026-11-10 06:00:00');
        Schema::create('money_table_rows', function (Blueprint $table): void {
            $table->id();
            $table->string('who');
            $table->string('kind');
            $table->decimal('amount', 14, 2)->nullable();
            $table->timestamp('happened_at');
        });
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        parent::tearDown();
    }

    public function test_search_finds_rows_that_are_not_on_the_first_page(): void
    {
        $this->seedRows(30);
        $this->addRow(['who' => 'Rahim Traders']);

        $result = $this->table(['q' => 'rahim', 'period' => '']);

        $this->assertSame(1, $result['totals']['count']);
        $this->assertSame('Rahim Traders', $result['rows']->items()[0]->who);
        $this->assertSame('rahim', $result['state']['q']);
    }

    public function test_search_words_are_literal_and_may_match_different_columns(): void
    {
        $this->addRow(['who' => 'Rahim Traders', 'kind' => 'referral']);
        $this->addRow(['who' => 'Karim 50% Store', 'kind' => 'area']);

        $this->assertSame(2, $this->table(['period' => ''])['totals']['count']);
        $this->assertSame(1, $this->table(['q' => '50%', 'period' => ''])['totals']['count']);
        $this->assertSame(1, $this->table(['q' => '%', 'period' => ''])['totals']['count']);
        $this->assertSame(1, $this->table(['q' => 'rahim referral', 'period' => ''])['totals']['count']);
        $this->assertSame(0, $this->table(['q' => "rahim' OR '1'='1", 'period' => ''])['totals']['count']);
    }

    public function test_search_reads_bangla_digits_as_the_digits_stored_in_the_database(): void
    {
        $this->addRow(['who' => 'MN-2611']);

        $result = $this->table(['q' => 'MN-২৬১১', 'period' => '']);

        $this->assertSame(1, $result['totals']['count']);
        $this->assertSame('MN-২৬১১', $result['state']['q']);
    }

    public function test_search_keeps_joining_marks_and_survives_control_characters(): void
    {
        $this->addRow(['who' => "র\u{200D}্যাঙ্ক"]);

        $this->assertSame(1, $this->table(['q' => "র\u{200D}্যাঙ্ক", 'period' => ''])['totals']['count']);
        $this->assertSame(1, $this->table(['q' => "\0র\u{200D}্যাঙ্ক", 'period' => ''])['totals']['count']);
        $this->assertSame(0, $this->table(['q' => "\xC3\x28", 'period' => ''])['totals']['count']);
    }

    public function test_each_view_picks_the_right_rows_in_dhaka_time(): void
    {
        $this->addRow(['who' => 'just after midnight today', 'happened_at' => '2026-11-09 18:30:00']);
        $this->addRow(['who' => 'just before midnight yesterday', 'happened_at' => '2026-11-09 17:30:00']);
        $this->addRow(['who' => 'earlier this week', 'happened_at' => '2026-11-08 18:30:00']);
        $this->addRow(['who' => 'last week this month', 'happened_at' => '2026-11-03 06:00:00']);
        $this->addRow(['who' => 'earlier this year', 'happened_at' => '2026-02-01 06:00:00']);
        $this->addRow(['who' => 'last year', 'happened_at' => '2025-12-01 06:00:00']);

        $this->assertSame(['just after midnight today'], $this->namesFor(['period' => 'today']));
        $this->assertSame(['earlier this week', 'just after midnight today', 'just before midnight yesterday'], $this->namesFor(['period' => 'week']));
        $this->assertCount(4, $this->namesFor(['period' => 'month']));
        $this->assertCount(5, $this->namesFor(['period' => 'year']));
        $this->assertSame(['last week this month'], $this->namesFor(['period' => 'day', 'from' => '2026-11-03']));
        $this->assertSame(['earlier this week', 'just before midnight yesterday', 'last week this month'], $this->namesFor(['period' => 'custom', 'from' => '2026-11-03', 'to' => '2026-11-09']));
        $this->assertCount(6, $this->namesFor(['period' => '']));
    }

    public function test_view_state_is_what_was_actually_applied(): void
    {
        $this->assertSame(['month', null, null], $this->viewState([]));
        $this->assertSame(['', null, null], $this->viewState(['period' => '']));
        $this->assertSame(['today', null, null], $this->viewState(['period' => 'today', 'from' => '2026-01-01', 'to' => '2026-01-02']));
        $this->assertSame(['day', '2026-11-03', null], $this->viewState(['period' => 'day', 'from' => '2026-11-03', 'to' => '2026-11-04']));
        $this->assertSame(['custom', '2026-11-01', '2026-11-03'], $this->viewState(['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-03']));
    }

    public function test_unknown_or_incomplete_views_fall_back_to_the_default(): void
    {
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'last_month']));
        $this->assertSame(['month', null, null], $this->viewState(['period' => ['today']]));
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'day']));
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'day', 'from' => '2026-02-31']));
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'day', 'from' => '2026-13-45']));
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'custom', 'from' => '2026-11-01']));
        $this->assertSame(['month', null, null], $this->viewState(['period' => 'custom', 'from' => ['a'], 'to' => '2026-11-03']));
    }

    public function test_a_table_can_default_to_all_time(): void
    {
        $this->addRow(['happened_at' => '2020-01-01 06:00:00']);

        $result = $this->table([], ['defaultPeriod' => null]);

        $this->assertSame('', $result['state']['period']);
        $this->assertSame(1, $result['totals']['count']);
    }

    public function test_sorting_covers_every_page_not_just_the_rows_on_screen(): void
    {
        $this->seedRows(60);

        $firstPage = $this->table(['period' => '', 'sort' => 'amount', 'dir' => 'desc']);
        $lastPage = $this->table(['period' => '', 'sort' => 'amount', 'dir' => 'desc', 'page' => '3']);

        $this->assertSame('60.00', $firstPage['rows']->items()[0]->amount);
        $this->assertSame('1.00', $lastPage['rows']->items()[9]->amount);
        $this->assertSame('amount', $firstPage['state']['sort']);
        $this->assertSame('desc', $firstPage['state']['dir']);
    }

    public function test_a_sort_that_is_not_allowed_is_ignored(): void
    {
        $this->addRow(['who' => 'a', 'amount' => '5.00', 'happened_at' => '2026-11-01 06:00:00']);
        $this->addRow(['who' => 'b', 'amount' => '9.00', 'happened_at' => '2026-11-02 06:00:00']);

        foreach (['id; DROP TABLE money_table_rows', 'who', ['amount'], ''] as $badSort) {
            $result = $this->table(['period' => '', 'sort' => $badSort, 'dir' => 'asc']);

            $this->assertSame('date', $result['state']['sort']);
            $this->assertSame('desc', $result['state']['dir']);
            $this->assertSame('b', $result['rows']->items()[0]->who);
        }

        $this->assertSame('desc', $this->table(['period' => '', 'sort' => 'amount', 'dir' => 'sideways'])['state']['dir']);
        $this->assertSame(['date', 'amount'], $this->table(['period' => ''])['sortable']);
    }

    public function test_rows_with_the_same_sort_value_never_repeat_between_pages(): void
    {
        for ($row = 0; $row < 12; $row++) {
            $this->addRow(['who' => 'row '.$row, 'amount' => '7.00']);
        }

        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $result = $this->table(['period' => '', 'sort' => 'amount', 'page' => (string) $page], ['perPage' => 5]);
            foreach ($result['rows']->items() as $row) {
                $seen[] = $row->id;
            }
        }

        $this->assertCount(12, array_unique($seen));
    }

    public function test_rows_with_no_amount_stay_at_the_bottom_in_both_directions(): void
    {
        $this->addRow(['who' => 'nothing', 'amount' => null]);
        $this->addRow(['who' => 'some', 'amount' => '3.00']);

        $this->assertSame('some', $this->table(['period' => '', 'sort' => 'amount', 'dir' => 'desc'])['rows']->items()[0]->who);
        $this->assertSame('some', $this->table(['period' => '', 'sort' => 'amount', 'dir' => 'asc'])['rows']->items()[0]->who);
    }

    public function test_totals_cover_the_whole_filtered_set_with_one_query_and_no_second_count(): void
    {
        $this->seedRows(60);

        DB::enableQueryLog();
        $result = $this->table(['period' => '', 'kind' => 'odd']);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertSame(30, $result['totals']['count']);
        $this->assertFalse($result['totals']['capped']);
        $this->assertSame(['amount' => '900.00'], $result['totals']['sums']);
        $this->assertSame(30, $result['rows']->total());
        $this->assertCount(25, $result['rows']->items());
        $this->assertCount(2, $queries);
    }

    public function test_a_sum_of_no_rows_is_zero_and_a_negative_sum_keeps_its_sign(): void
    {
        $this->assertSame(['amount' => '0.00'], $this->table(['period' => ''])['totals']['sums']);

        $this->addRow(['amount' => '-12.50']);
        $this->addRow(['amount' => '2.25']);

        $this->assertSame(['amount' => '-10.25'], $this->table(['period' => ''])['totals']['sums']);
    }

    public function test_sums_can_be_switched_off_when_they_would_mean_nothing(): void
    {
        $this->seedRows(2);
        $onlyWithOneKind = fn (array $filters): bool => isset($filters['kind']);

        $this->assertNull($this->table(['period' => ''], ['sumsWhen' => $onlyWithOneKind])['totals']['sums']);
        $this->assertSame(['amount' => '1.00'], $this->table(['period' => '', 'kind' => 'odd'], ['sumsWhen' => $onlyWithOneKind])['totals']['sums']);
    }

    public function test_count_cap_stops_counting_and_skips_the_sums(): void
    {
        $this->seedRows(12);

        $capped = $this->table(['period' => ''], ['countCap' => 10, 'perPage' => 5]);
        $under = $this->table(['period' => ''], ['countCap' => 12, 'perPage' => 5]);

        $this->assertSame(10, $capped['totals']['count']);
        $this->assertTrue($capped['totals']['capped']);
        $this->assertNull($capped['totals']['sums']);
        $this->assertSame(2, $capped['rows']->lastPage());
        $this->assertSame(12, $under['totals']['count']);
        $this->assertFalse($under['totals']['capped']);
        $this->assertSame(['amount' => '78.00'], $under['totals']['sums']);
    }

    public function test_totals_add_up_a_grouped_query_by_its_rows(): void
    {
        $this->addRow(['who' => 'a', 'amount' => '1.00']);
        $this->addRow(['who' => 'a', 'amount' => '2.00']);
        $this->addRow(['who' => 'b', 'amount' => '4.00']);

        $query = DB::table('money_table_rows')->selectRaw('who, SUM(amount) AS total')->groupBy('who');
        $spec = new MoneyTableSpec(null, [], [], ['who' => 'who', 'total' => 'SUM(amount)'], 'who', 'who', sums: ['total' => 'total'], defaultPeriod: null);

        $result = MoneyTable::make(Request::create('/x', 'GET', ['sort' => 'total']), $query, $spec);

        $this->assertSame(2, $result['totals']['count']);
        $this->assertSame(['total' => '7.00'], $result['totals']['sums']);
        $this->assertSame('b', $result['rows']->items()[0]->who);
    }

    public function test_a_filter_value_that_is_not_offered_is_ignored(): void
    {
        $this->seedRows(4);

        $good = $this->table(['period' => '', 'kind' => 'odd']);
        $bad = $this->table(['period' => '', 'kind' => "odd' OR 1=1"]);
        $array = $this->table(['period' => '', 'kind' => ['odd']]);

        $this->assertSame(2, $good['totals']['count']);
        $this->assertSame(['kind' => 'odd'], $good['state']['filters']);
        $this->assertSame(4, $bad['totals']['count']);
        $this->assertSame([], $bad['state']['filters']);
        $this->assertSame(4, $array['totals']['count']);
        $this->assertSame('Kind', $good['filters'][0]['label']);
        $this->assertSame(['key' => 'kind', 'label' => 'Kind', 'options' => [['value' => 'odd', 'label' => 'Odd'], ['value' => 'even', 'label' => 'Even']]], $good['filters'][0]);
    }

    public function test_a_number_range_filter_keeps_rows_between_both_ends(): void
    {
        $this->seedRows(5);
        $spec = ['filters' => [MoneyTableFilter::numberRange('amount', 'Amount', 'amount', 'min_amount', 'max_amount', decimal: true)]];

        $between = $this->table(['period' => '', 'min_amount' => '২', 'max_amount' => '4.50'], $spec);
        $lowerOnly = $this->table(['period' => '', 'min_amount' => '4'], $spec);
        $junk = $this->table(['period' => '', 'min_amount' => "1' OR 1=1", 'max_amount' => '-3'], $spec);

        $this->assertSame(3, $between['totals']['count']);
        $this->assertSame(['min_amount' => '2', 'max_amount' => '4.50'], $between['state']['filters']);
        $this->assertSame(2, $lowerOnly['totals']['count']);
        $this->assertSame(5, $junk['totals']['count']);
        $this->assertSame([], $junk['state']['filters']);
    }

    public function test_a_whole_number_range_refuses_decimals(): void
    {
        $this->seedRows(3);
        $spec = ['filters' => [MoneyTableFilter::numberRange('amount', 'Amount', 'amount', 'min_amount', 'max_amount')]];

        $this->assertSame([], $this->table(['period' => '', 'min_amount' => '1.5'], $spec)['state']['filters']);
    }

    public function test_a_date_range_filter_reads_either_order_and_ignores_bad_dates(): void
    {
        $this->addRow(['who' => 'early', 'happened_at' => '2026-10-02 05:00:00']);
        $this->addRow(['who' => 'late', 'happened_at' => '2026-11-02 05:00:00']);
        $spec = ['filters' => [MoneyTableFilter::dateRange('when', 'When', 'happened_at::date', 'when_from', 'when_to')]];

        $forwards = $this->table(['period' => '', 'when_from' => '2026-11-01', 'when_to' => '2026-11-30'], $spec);
        $backwards = $this->table(['period' => '', 'when_from' => '2026-10-31', 'when_to' => '2026-10-01'], $spec);
        $impossible = $this->table(['period' => '', 'when_from' => '2026-02-31'], $spec);

        $this->assertSame('late', $forwards['rows']->items()[0]->who);
        $this->assertSame(1, $forwards['totals']['count']);
        $this->assertSame('early', $backwards['rows']->items()[0]->who);
        $this->assertSame(['when_from' => '2026-10-01', 'when_to' => '2026-10-31'], $backwards['state']['filters']);
        $this->assertSame(2, $impossible['totals']['count']);
    }

    public function test_a_text_filter_matches_part_of_a_value_literally(): void
    {
        $this->addRow(['who' => 'Batch 50% off']);
        $this->addRow(['who' => 'Batch 500']);
        $spec = ['filters' => [MoneyTableFilter::text('batch', 'Batch', 'who')]];

        $result = $this->table(['period' => '', 'batch' => '  50%  '], $spec);

        $this->assertSame(1, $result['totals']['count']);
        $this->assertSame('Batch 50% off', $result['rows']->items()[0]->who);
        $this->assertSame(['batch' => '50%'], $result['state']['filters']);
    }

    public function test_a_filter_with_no_column_is_reported_but_left_to_the_read_model(): void
    {
        $this->seedRows(4);
        $spec = ['filters' => [new MoneyTableFilter('kind', 'Kind', null, [['value' => 'odd', 'label' => 'Odd']])]];

        $result = $this->table(['period' => '', 'kind' => 'odd'], $spec);

        $this->assertSame(4, $result['totals']['count']);
        $this->assertSame(['kind' => 'odd'], $result['state']['filters']);
    }

    public function test_the_screen_is_told_how_to_draw_each_filter_and_its_option_counts(): void
    {
        $spec = ['filters' => [
            new MoneyTableFilter('kind', 'Kind', 'kind', [['value' => 'odd', 'label' => 'Odd', 'count' => 7]], group: 'more'),
            MoneyTableFilter::numberRange('amount', 'Amount', 'amount', 'min_amount', 'max_amount', decimal: true),
            MoneyTableFilter::dateRange('when', 'When', 'happened_at::date', 'when_from', 'when_to', group: 'main'),
            MoneyTableFilter::text('batch', 'Batch', 'who'),
        ]];

        $filters = $this->table(['period' => ''], $spec)['filters'];

        $this->assertSame(['key' => 'kind', 'label' => 'Kind', 'options' => [['value' => 'odd', 'label' => 'Odd', 'count' => 7]], 'group' => 'more'], $filters[0]);
        $this->assertSame(['key' => 'amount', 'label' => 'Amount', 'options' => [], 'type' => 'range', 'group' => 'more', 'from' => 'min_amount', 'to' => 'max_amount', 'decimal' => true], $filters[1]);
        $this->assertSame(['key' => 'when', 'label' => 'When', 'options' => [], 'type' => 'date_range', 'from' => 'when_from', 'to' => 'when_to'], $filters[2]);
        $this->assertSame(['key' => 'batch', 'label' => 'Batch', 'options' => [], 'type' => 'text', 'group' => 'more'], $filters[3]);
    }

    public function test_an_extra_period_works_only_where_the_table_offers_it(): void
    {
        $this->addRow(['who' => 'October', 'happened_at' => '2026-10-15 05:00:00']);
        $this->addRow(['who' => 'November']);

        $offered = $this->table(['period' => 'last_month'], ['extraPeriods' => ['last_month']]);
        $notOffered = $this->table(['period' => 'last_month']);

        $this->assertSame('last_month', $offered['state']['period']);
        $this->assertSame('October', $offered['rows']->items()[0]->who);
        $this->assertSame(1, $offered['totals']['count']);
        $this->assertSame(['last_month'], $offered['extra_periods']);
        $this->assertSame('month', $notOffered['state']['period']);
        $this->assertSame('November', $notOffered['rows']->items()[0]->who);
        $this->assertSame([], $notOffered['extra_periods']);

        $request = Request::create('/x', 'GET', ['period' => 'last_month']);
        $this->assertSame('2026-09-30 18:00:00', MoneyTable::range($request, null, ['last_month'])['from']->format('Y-m-d H:i:s'));
        $this->assertNull(MoneyTable::range($request, null));
    }

    public function test_a_range_over_366_days_is_refused_and_366_days_is_allowed(): void
    {
        $this->assertSame('custom', $this->table(['period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-01-02'])['state']['period']);

        app()->setLocale('bn');
        try {
            $this->table(['period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-01-03']);
            $this->fail('A range over 366 days must be refused.');
        } catch (ValidationException $refused) {
            $this->assertSame(['সর্বোচ্চ ৩৬৬ দিনের তারিখ বেছে নিন।'], $refused->errors()['money_table_range']);
        }
    }

    public function test_a_range_that_ends_before_it_starts_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->table(['period' => 'custom', 'from' => '2026-11-05', 'to' => '2026-11-01']);
    }

    public function test_range_gives_read_models_the_same_dhaka_window(): void
    {
        $range = MoneyTable::range(Request::create('/x', 'GET', ['period' => 'day', 'from' => '2026-11-03']), 'month');

        $this->assertSame('2026-11-02 18:00:00', $range['from']->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 17:59:59', $range['until']->format('Y-m-d H:i:s'));
        $this->assertNull(MoneyTable::range(Request::create('/x', 'GET', ['period' => '']), 'month'));
        $this->assertNull(MoneyTable::range(Request::create('/x', 'GET'), null));
        $this->assertSame('2026-10-31 18:00:00', MoneyTable::range(Request::create('/x', 'GET'), 'month')['from']->format('Y-m-d H:i:s'));
    }

    public function test_a_page_past_the_end_shows_the_last_page_and_links_keep_the_view(): void
    {
        $this->seedRows(30);

        $result = $this->table(['period' => '', 'tab' => 'in', 'page' => '999']);
        $junkPage = $this->table(['period' => '', 'page' => 'abc']);

        $this->assertSame(2, $result['rows']->currentPage());
        $this->assertStringContainsString('tab=in', (string) $result['rows']->previousPageUrl());
        $this->assertSame(1, $junkPage['rows']->currentPage());
    }

    public function test_the_query_passed_in_is_left_as_it_was(): void
    {
        $this->seedRows(4);
        $query = $this->baseQuery();

        MoneyTable::make(Request::create('/x', 'GET', ['period' => '', 'kind' => 'odd', 'q' => 'row']), $query, $this->spec());

        $this->assertSame(4, $query->count());
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @param  array<string, mixed>  $specChanges
     * @return array<string, mixed>
     */
    private function table(array $parameters, array $specChanges = []): array
    {
        return MoneyTable::make(Request::create('/x', 'GET', $parameters), $this->baseQuery(), $this->spec($specChanges));
    }

    private function baseQuery(): Builder
    {
        return DB::table('money_table_rows')->select(['id', 'who', 'kind', 'amount', 'happened_at']);
    }

    /** @param array<string, mixed> $changes */
    private function spec(array $changes = []): MoneyTableSpec
    {
        return new MoneyTableSpec(...array_merge([
            'dateColumn' => 'happened_at',
            'search' => ['who', 'kind'],
            'filters' => [new MoneyTableFilter('kind', 'Kind', 'kind', [['value' => 'odd', 'label' => 'Odd'], ['value' => 'even', 'label' => 'Even']])],
            'sorts' => ['date' => 'happened_at', 'amount' => 'amount'],
            'defaultSort' => 'date',
            'tieBreaker' => 'id',
            'sums' => ['amount' => 'amount'],
        ], $changes));
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return list<string>
     */
    private function namesFor(array $parameters): array
    {
        $names = array_map(fn (object $row): string => $row->who, $this->table($parameters)['rows']->items());
        sort($names);

        return $names;
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{0: string, 1: ?string, 2: ?string}
     */
    private function viewState(array $parameters): array
    {
        $state = $this->table($parameters)['state'];

        return [$state['period'], $state['from'], $state['to']];
    }

    private function seedRows(int $count): void
    {
        for ($row = 1; $row <= $count; $row++) {
            $this->addRow(['who' => 'row '.$row, 'kind' => $row % 2 === 1 ? 'odd' : 'even', 'amount' => $row.'.00']);
        }
    }

    /** @param array<string, mixed> $values */
    private function addRow(array $values): void
    {
        DB::table('money_table_rows')->insert(array_merge([
            'who' => 'someone',
            'kind' => 'odd',
            'amount' => '1.00',
            'happened_at' => '2026-11-10 05:00:00',
        ], $values));
    }
}
