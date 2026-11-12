<?php

namespace Tests\Feature;

use App\Domain\Audience\SiteVisitCounter;
use App\Domain\Audience\SiteVisitSettings;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class VisitorsPageTest extends TestCase
{
    use RefreshDatabase;

    /** A Thursday afternoon; the week runs Sunday 8 to Saturday 14 November. */
    private const Now = '2026-11-12 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse(self::Now, 'Asia/Dhaka'));
    }

    /** One page opened by a visitor at a Dhaka time, counted the way the public site counts it. */
    private function seen(string $when, string $visitor, string $page = '/', string $source = 'direct', string $device = 'desktop'): void
    {
        $this->travelTo(CarbonImmutable::parse($when, 'Asia/Dhaka'));
        app(SiteVisitCounter::class)->record(str_pad($visitor, 32, 'x'), $page, $source, $device);
        $this->travelTo(CarbonImmutable::parse(self::Now, 'Asia/Dhaka'));
    }

    /**
     * What the visitors page shows an owner for an address such as "?period=today".
     *
     * @return array<string, mixed>
     */
    private function page(string $query = '', ?User $owner = null): array
    {
        $response = $this->actingAs($owner ?? User::factory()->staff()->create())->onPortal('sadmin')->get('/visitors'.$query)->assertOk();

        return $response->viewData('page')['props']['data']['visitors'];
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['Super Admin' => ['super_admin'], 'MD' => ['md'], 'Chairman' => ['chairman']];
    }

    #[DataProvider('owners')]
    public function test_each_owner_can_open_the_visitors_page(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/visitors')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('section', 'visitors')
                ->where('data.visitors.counting.enabled', true)
                ->where('data.visitors.summary.views', 0)
                ->where('data.visitors.summary.visitors', 0)
                ->where('data.visitors.active_now', 0));
    }

    public function test_office_staff_have_no_visitors_page(): void
    {
        foreach (['editor', 'finance', 'support'] as $role) {
            $this->actingAs(User::factory()->staff($role)->create())->onPortal('manage')->get('/visitors')->assertNotFound();
        }
    }

    public function test_office_staff_cannot_reach_it_through_the_owner_panel_either(): void
    {
        $this->actingAs(User::factory()->staff('editor')->create())->onPortal('sadmin')->get('/visitors')->assertForbidden();
    }

    public function test_partners_have_no_visitors_page(): void
    {
        $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->get('/visitors')->assertNotFound();
        $this->actingAs(User::factory()->subDealer()->create())->onPortal('subdealer')->get('/visitors')->assertNotFound();
    }

    public function test_someone_not_signed_in_is_sent_to_sign_in(): void
    {
        $this->onPortal('sadmin')->get('/visitors')->assertRedirect('/login');
    }

    public function test_the_totals_follow_the_chosen_period(): void
    {
        $this->seen('2026-11-12 09:00', 'a', '/');
        $this->seen('2026-11-12 09:05', 'a', '/products');
        $this->seen('2026-11-12 11:00', 'b', '/');
        $this->seen('2026-11-11 18:00', 'a', '/');
        $this->seen('2026-11-03 12:00', 'c', '/');
        $this->seen('2026-10-20 12:00', 'd', '/');
        $this->seen('2026-10-20 12:30', 'd', '/contact');
        $owner = User::factory()->staff()->create();

        $expected = [
            '' => [5, 4],
            '?period=today' => [3, 2],
            '?period=yesterday' => [1, 1],
            '?period=week' => [4, 3],
            '?period=last_week' => [1, 1],
            '?period=month' => [5, 4],
            '?period=last_month' => [2, 1],
            '?period=year' => [7, 5],
            '?period=all' => [7, 5],
            '?period=day&from=2026-11-03' => [1, 1],
            '?period=custom&from=2026-10-20&to=2026-11-03' => [3, 2],
        ];
        foreach ($expected as $query => [$views, $visitors]) {
            $summary = $this->page($query, $owner)['summary'];

            $this->assertSame($views, $summary['views'], 'views '.$query);
            $this->assertSame($visitors, $summary['visitors'], 'visitors '.$query);
        }
    }

    public function test_the_page_says_which_days_it_is_showing(): void
    {
        $this->assertSame(['key' => 'last_week', 'from' => '2026-11-01', 'to' => '2026-11-07', 'bucket' => 'day'], $this->page('?period=last_week')['period']);
        $this->assertSame(['key' => 'month', 'from' => '2026-11-01', 'to' => '2026-11-12', 'bucket' => 'day'], $this->page()['period']);
        $this->assertSame(['key' => 'today', 'from' => '2026-11-12', 'to' => '2026-11-12', 'bucket' => 'hour'], $this->page('?period=today')['period']);
    }

    public function test_a_day_or_range_without_its_dates_falls_back_to_this_month(): void
    {
        $this->assertSame('month', $this->page('?period=day')['period']['key']);
        $this->assertSame('month', $this->page('?period=custom&from=2026-11-01')['period']['key']);
    }

    public function test_today_is_compared_with_yesterday_up_to_the_same_time(): void
    {
        $this->seen('2026-11-11 10:00', 'a');
        $this->seen('2026-11-11 11:00', 'b');
        // After 15:00 yesterday: today has not had this part of the day yet, so it is left out.
        $this->seen('2026-11-11 20:00', 'c');
        $this->seen('2026-11-12 09:00', 'a');
        $this->seen('2026-11-12 10:00', 'b');
        $this->seen('2026-11-12 11:00', 'c');

        $summary = $this->page('?period=today')['summary'];

        $this->assertSame(50, $summary['views_change']);
        $this->assertSame(50, $summary['visitors_change']);
    }

    public function test_a_finished_week_is_compared_with_the_week_before_it(): void
    {
        $this->seen('2026-10-27 10:00', 'a');
        $this->seen('2026-10-28 10:00', 'a');
        $this->seen('2026-10-29 10:00', 'a');
        $this->seen('2026-10-30 10:00', 'a');
        $this->seen('2026-11-03 10:00', 'a');

        $summary = $this->page('?period=last_week')['summary'];

        $this->assertSame(-75, $summary['views_change']);
        $this->assertSame(-75, $summary['visitors_change']);
    }

    public function test_there_is_no_percentage_when_the_time_before_had_no_visits(): void
    {
        $this->seen('2026-11-11 10:00', 'a');

        $summary = $this->page('?period=yesterday')['summary'];

        $this->assertNull($summary['views_change']);
        $this->assertNull($summary['visitors_change']);
        $this->assertNull($this->page('?period=all')['summary']['views_change']);
    }

    public function test_one_day_is_charted_by_the_hour(): void
    {
        $this->seen('2026-11-12 09:30', 'a');
        $this->seen('2026-11-12 09:45', 'a');
        $this->seen('2026-11-12 14:10', 'b');

        $trend = collect($this->page('?period=today')['trend'])->keyBy('key');

        $this->assertCount(24, $trend);
        $this->assertSame(['key' => '09', 'views' => 2, 'visitors' => 1], $trend['09']);
        $this->assertSame(['key' => '14', 'views' => 1, 'visitors' => 1], $trend['14']);
        $this->assertSame(['key' => '00', 'views' => 0, 'visitors' => 0], $trend['00']);
    }

    public function test_several_days_are_charted_day_by_day_with_empty_days_shown(): void
    {
        $this->seen('2026-11-08 10:00', 'a');
        $this->seen('2026-11-12 10:00', 'a');
        $this->seen('2026-11-12 11:00', 'b');

        $trend = $this->page('?period=week')['trend'];

        $this->assertSame(['2026-11-08', '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12'], array_column($trend, 'key'));
        $this->assertSame([1, 0, 0, 0, 2], array_column($trend, 'views'));
        $this->assertSame([1, 0, 0, 0, 2], array_column($trend, 'visitors'));
    }

    public function test_a_long_time_is_charted_month_by_month(): void
    {
        $this->seen('2026-01-15 10:00', 'a');
        $this->seen('2026-11-12 10:00', 'a');

        $page = $this->page('?period=all');

        $this->assertSame('month', $page['period']['bucket']);
        $this->assertSame('2026-01-15', $page['period']['from']);
        $this->assertSame(['2026-01', '2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'], array_column($page['trend'], 'key'));
        $this->assertSame(1, $page['trend'][0]['views']);
        $this->assertSame(1, $page['trend'][10]['views']);
    }

    public function test_phones_and_computers_are_counted_apart(): void
    {
        $this->seen('2026-11-12 09:00', 'a', device: 'mobile');
        $this->seen('2026-11-12 09:10', 'a', device: 'mobile');
        $this->seen('2026-11-12 09:20', 'b', device: 'desktop');

        $this->assertSame(['mobile' => 2, 'desktop' => 1], $this->page('?period=today')['devices']);
    }

    public function test_arrivals_are_listed_by_where_they_came_from_and_moving_between_our_own_pages_is_left_out(): void
    {
        $this->seen('2026-11-12 09:00', 'a', source: 'facebook');
        $this->seen('2026-11-12 09:01', 'a', '/products', source: 'internal');
        $this->seen('2026-11-12 09:10', 'b', source: 'facebook');
        $this->seen('2026-11-12 09:20', 'c', source: 'google');

        $this->assertSame(
            [['source' => 'facebook', 'views' => 2], ['source' => 'google', 'views' => 1]],
            $this->page('?period=today')['sources'],
        );
    }

    public function test_the_most_opened_pages_and_products_come_first(): void
    {
        $product = Product::factory()->create(['name' => 'Nim soap']);
        $other = Product::factory()->create(['name' => 'Black seed oil']);
        $this->seen('2026-11-12 09:00', 'a', '/products/'.$product->id);
        $this->seen('2026-11-12 09:10', 'b', '/products/'.$product->id);
        $this->seen('2026-11-12 09:20', 'c', '/products/'.$other->id);
        $this->seen('2026-11-12 09:30', 'c', '/');
        $this->seen('2026-11-12 09:40', 'c', '/');
        $this->seen('2026-11-12 09:50', 'c', '/');

        $page = $this->page('?period=today');

        $this->assertSame(['path' => '/', 'views' => 3], $page['pages'][0]);
        $this->assertSame(['path' => '/products/'.$product->id, 'views' => 2], $page['pages'][1]);
        $this->assertSame([
            ['id' => $product->id, 'name' => 'Nim soap', 'views' => 2],
            ['id' => $other->id, 'name' => 'Black seed oil', 'views' => 1],
        ], $page['products']);
    }

    public function test_people_on_the_site_now_are_those_seen_in_the_last_fifteen_minutes(): void
    {
        $this->seen('2026-11-12 14:30', 'a');
        $this->seen('2026-11-12 14:50', 'b');
        $this->seen('2026-11-12 14:55', 'b', '/products');
        $this->seen('2026-11-12 14:58', 'c');

        $this->assertSame(2, $this->page()['active_now']);
    }

    public function test_the_latest_visits_are_listed_newest_first_whatever_the_period(): void
    {
        $this->seen('2026-11-10 10:00', 'a', '/about', 'google', 'desktop');
        $this->seen('2026-11-12 14:00', 'b', '/contact', 'facebook', 'mobile');

        $recent = $this->page('?period=yesterday')['recent'];

        $this->assertCount(2, $recent);
        $this->assertSame(['path' => '/contact', 'device' => 'mobile', 'source' => 'facebook'], array_intersect_key($recent[0], ['path' => 1, 'device' => 1, 'source' => 1]));
        $this->assertSame('/about', $recent[1]['path']);
        $this->assertSame('2026-11-12T08:00:00+00:00', $recent[0]['at']);
        $this->assertArrayNotHasKey('visitor_id', $recent[0]);
    }

    public function test_the_page_warns_when_the_details_no_longer_reach_back_as_far_as_the_totals(): void
    {
        $this->seen('2025-06-01 10:00', 'a');
        $this->seen('2026-11-12 10:00', 'b');
        $this->artisan('visits:prune');

        $all = $this->page('?period=all');

        $this->assertTrue($all['details_partial']);
        $this->assertSame(2, $all['summary']['views']);
        $this->assertSame(['mobile' => 0, 'desktop' => 1], $all['devices']);
        $this->assertFalse($this->page('?period=month')['details_partial']);
    }

    #[DataProvider('owners')]
    public function test_each_owner_can_switch_counting_off_and_on_again(string $role): void
    {
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->post('/commands/owner/site-visit-counting', ['enabled' => '0', 'version' => 0])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertFalse(app(SiteVisitSettings::class)->countingEnabled());
        $this->assertSame(['enabled' => false, 'version' => 1], $this->page('', $owner)['counting']);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'website.visit_counting_changed')->where('actor_id', $owner->id)->count());

        $this->actingAs($owner)->post('/commands/owner/site-visit-counting', ['enabled' => '1', 'version' => 1])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(SiteVisitSettings::class)->countingEnabled());
    }

    public function test_office_staff_and_partners_cannot_change_the_switch(): void
    {
        foreach ([User::factory()->staff('editor')->create(), User::factory()->staff('finance')->create(), User::factory()->dealer()->create()] as $person) {
            $this->actingAs($person)->post('/commands/owner/site-visit-counting', ['enabled' => '0', 'version' => 0])->assertForbidden();
        }

        $this->assertTrue(app(SiteVisitSettings::class)->countingEnabled());
    }

    public function test_a_switch_form_left_open_while_another_owner_changed_it_is_refused(): void
    {
        $owner = User::factory()->staff()->create();
        app(SiteVisitSettings::class)->save(User::factory()->staff('md')->create(), false, 0);

        $this->actingAs($owner)->post('/commands/owner/site-visit-counting', ['enabled' => '1', 'version' => 0])->assertSessionHasErrors('version');

        $this->assertFalse(app(SiteVisitSettings::class)->countingEnabled());
    }

    public function test_the_switch_needs_a_clear_on_or_off(): void
    {
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->post('/commands/owner/site-visit-counting', ['enabled' => 'maybe', 'version' => 0])->assertSessionHasErrors('enabled');
        $this->actingAs($owner)->post('/commands/owner/site-visit-counting', ['enabled' => '0'])->assertSessionHasErrors('version');
    }

    public function test_the_visitors_tab_is_offered_to_owners_only(): void
    {
        $tabs = fn (User $person, string $portal, string $address): array => array_column(
            $this->actingAs($person)->onPortal($portal)->get($address)->assertOk()->viewData('page')['props']['data']['hub']['tabs'] ?? [],
            'key',
        );

        $this->assertContains('visitors', $tabs(User::factory()->staff()->create(), 'sadmin', '/website'));
        $this->assertNotContains('visitors', $tabs(User::factory()->staff('editor')->create(), 'manage', '/website'));
        // The MD has no website tabs of their own, so the row is just this page.
        $this->assertSame(['visitors'], $tabs(User::factory()->staff('md')->create(), 'sadmin', '/visitors'));
    }

    public function test_the_page_has_its_words_in_both_languages(): void
    {
        $english = require lang_path('en/office_visitors.php');
        $bangla = require lang_path('bn/office_visitors.php');

        $this->assertSame(array_keys($english), array_keys($bangla));
        foreach (['bn', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertNotSame('portal.visitors', __('portal.visitors'), $locale);
            $this->assertNotSame('office_menu.tab_visitors', __('office_menu.tab_visitors'), $locale);
        }
    }
}
