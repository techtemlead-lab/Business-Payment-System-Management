<?php

namespace Tests\Feature;

use App\Domain\Audience\SiteVisitCounter;
use App\Domain\Audience\SiteVisitSettings;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SiteVisitCountingTest extends TestCase
{
    use RefreshDatabase;

    private const Desktop = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const Phone = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Mobile Safari/537.36';

    private const FirstVisitor = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const SecondVisitor = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Asia/Dhaka'));
    }

    /**
     * One page opened in a browser, as the public site's script reports it.
     *
     * @param  array<string, string>  $fields
     */
    private function see(string $path, ?string $visitor = self::FirstVisitor, string $browser = self::Desktop, array $fields = []): TestResponse
    {
        if ($visitor !== null) {
            $this->withCookie(SiteVisitCounter::VisitorCookie, $visitor);
        }

        return $this->withHeader('User-Agent', $browser)->post('/seen', ['path' => $path, ...$fields]);
    }

    /** @return array{views: int, visitors: int}|null */
    private function totalsFor(string $day): ?array
    {
        $row = DB::table('site_visit_days')->where('day', $day)->first();

        return $row === null ? null : ['views' => (int) $row->views, 'visitors' => (int) $row->visitors];
    }

    public function test_a_page_opened_on_the_public_site_is_counted(): void
    {
        $response = $this->see('/products', visitor: null);

        $response->assertNoContent();
        $response->assertCookie(SiteVisitCounter::VisitorCookie);
        $visit = DB::table('site_visits')->sole();
        $this->assertSame('/products', $visit->path);
        $this->assertSame('desktop', $visit->device);
        $this->assertSame('direct', $visit->source);
        $this->assertSame('2026-11-12', $visit->visited_on);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $visit->visitor_id);
        $this->assertSame(['views' => 1, 'visitors' => 1], $this->totalsFor('2026-11-12'));
    }

    public function test_the_same_browser_is_one_visitor_a_day_however_many_pages_it_opens(): void
    {
        $this->see('/');
        $this->see('/products');
        $this->see('/contact');

        $this->assertSame(['views' => 3, 'visitors' => 1], $this->totalsFor('2026-11-12'));
        $this->assertSame(1, DB::table('site_visits')->distinct()->count('visitor_id'));
    }

    public function test_two_browsers_are_two_visitors(): void
    {
        $this->see('/', self::FirstVisitor);
        $this->see('/', self::SecondVisitor);

        $this->assertSame(['views' => 2, 'visitors' => 2], $this->totalsFor('2026-11-12'));
    }

    public function test_a_visitor_who_comes_back_the_next_day_is_counted_again(): void
    {
        $this->see('/');
        $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00:00', 'Asia/Dhaka'));
        $this->see('/');

        $this->assertSame(['views' => 1, 'visitors' => 1], $this->totalsFor('2026-11-12'));
        $this->assertSame(['views' => 1, 'visitors' => 1], $this->totalsFor('2026-11-13'));
    }

    public function test_the_day_follows_dhaka_time_not_server_time(): void
    {
        // 01:00 in Dhaka is still the evening before in UTC.
        $this->travelTo(CarbonImmutable::parse('2026-11-13 01:00:00', 'Asia/Dhaka'));

        $this->see('/');

        $this->assertSame('2026-11-13', DB::table('site_visits')->sole()->visited_on);
        $this->assertNotNull($this->totalsFor('2026-11-13'));
    }

    public function test_a_cookie_that_is_not_one_of_ours_gets_a_new_visitor_number(): void
    {
        $response = $this->see('/', visitor: '<script>alert(1)</script>');

        $response->assertCookie(SiteVisitCounter::VisitorCookie);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', DB::table('site_visits')->sole()->visitor_id);
    }

    /** @return array<string, array{string}> */
    public static function botBrowsers(): array
    {
        return [
            'Google' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'],
            'Facebook link preview' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)'],
            'headless browser' => ['Mozilla/5.0 (X11; Linux x86_64) HeadlessChrome/130.0'],
            'no browser name at all' => [''],
        ];
    }

    #[DataProvider('botBrowsers')]
    public function test_search_engines_and_other_bots_are_not_counted(string $browser): void
    {
        $this->see('/', browser: $browser)->assertNoContent();

        $this->assertSame(0, DB::table('site_visits')->count());
        $this->assertNull($this->totalsFor('2026-11-12'));
    }

    public function test_nothing_is_counted_while_the_owner_has_counting_switched_off(): void
    {
        $owner = User::factory()->staff()->create();
        app(SiteVisitSettings::class)->save($owner, false, 0);

        $this->see('/')->assertNoContent()->assertCookieMissing(SiteVisitCounter::VisitorCookie);

        $this->assertSame(0, DB::table('site_visits')->count());
    }

    /** @return array<string, array{string}> */
    public static function officeRoles(): array
    {
        return ['Super Admin' => ['super_admin'], 'MD' => ['md'], 'Chairman' => ['chairman'], 'office staff' => ['finance']];
    }

    #[DataProvider('officeRoles')]
    public function test_the_companys_own_people_browsing_the_site_are_not_counted(string $role): void
    {
        $this->actingAs(User::factory()->staff($role)->create());

        $this->see('/')->assertNoContent();

        $this->assertSame(0, DB::table('site_visits')->count());
    }

    public function test_a_signed_in_dealer_is_a_visitor_like_anyone_else(): void
    {
        $this->actingAs(User::factory()->dealer()->create());

        $this->see('/products')->assertNoContent();

        $this->assertSame(1, DB::table('site_visits')->count());
    }

    public function test_a_referral_link_is_kept_without_the_partners_code(): void
    {
        $this->see('/join/MNHC-1010001');

        $this->assertSame('/join', DB::table('site_visits')->sole()->path);
    }

    public function test_a_trailing_slash_does_not_make_a_second_page(): void
    {
        $this->see('/products/');

        $this->assertSame('/products', DB::table('site_visits')->sole()->path);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function arrivals(): array
    {
        return [
            'typed the address' => [[], 'direct'],
            'Facebook' => [['referrer' => 'https://l.facebook.com/l.php?u=x'], 'facebook'],
            'Facebook app without a referrer' => [['tag' => 'fbclid'], 'facebook'],
            'Messenger' => [['referrer' => 'https://l.messenger.com/'], 'facebook'],
            'Google search' => [['referrer' => 'https://www.google.com/'], 'google'],
            'Google ad' => [['tag' => 'gclid'], 'google'],
            'YouTube' => [['referrer' => 'https://m.youtube.com/watch?v=x'], 'youtube'],
            'WhatsApp' => [['referrer' => 'https://web.whatsapp.com/'], 'whatsapp'],
            'Instagram' => [['referrer' => 'https://l.instagram.com/'], 'instagram'],
            'TikTok' => [['referrer' => 'https://www.tiktok.com/@x'], 'tiktok'],
            'another website' => [['referrer' => 'https://news.example.org/story'], 'other'],
            'another page of this site' => [['referrer' => 'http://example.test/products'], 'internal'],
            'a site that only looks like Google' => [['referrer' => 'https://google.com.evil.example/'], 'other'],
            'an unreadable referrer' => [['referrer' => 'not a link'], 'direct'],
        ];
    }

    /** @param  array<string, string>  $fields */
    #[DataProvider('arrivals')]
    public function test_where_the_visitor_came_from_is_recognised(array $fields, string $source): void
    {
        $this->see('/', fields: $fields);

        $this->assertSame($source, DB::table('site_visits')->sole()->source);
    }

    public function test_a_phone_is_recognised_by_its_browser(): void
    {
        $this->see('/', browser: self::Phone);

        $this->assertSame('mobile', DB::table('site_visits')->sole()->device);
    }

    public function test_a_narrow_screen_counts_as_a_phone_even_with_a_desktop_browser_name(): void
    {
        $this->see('/', fields: ['screen' => '390']);

        $this->assertSame('mobile', DB::table('site_visits')->sole()->device);
    }

    /** @return array<string, array{string}> */
    public static function notPages(): array
    {
        return [
            'nothing' => [''],
            'no leading slash' => ['products'],
            'a full address' => ['https://evil.example/'],
            'a script' => ['/<script>alert(1)</script>'],
            'a query string' => ['/products?phone=01700000000'],
            'far too long' => ['/'.str_repeat('a', 300)],
        ];
    }

    #[DataProvider('notPages')]
    public function test_something_that_is_not_a_page_address_is_ignored_quietly(string $path): void
    {
        $this->see($path)->assertNoContent();

        $this->assertSame(0, DB::table('site_visits')->count());
    }

    public function test_the_portals_do_not_take_visit_reports(): void
    {
        $this->withHeader('User-Agent', self::Desktop)->onPortal('dealer')->post('/seen', ['path' => '/'])->assertNotFound();

        $this->assertSame(0, DB::table('site_visits')->count());
    }

    public function test_visits_older_than_a_year_are_removed_and_the_daily_totals_stay(): void
    {
        $this->travelTo(CarbonImmutable::parse('2025-10-01 10:00:00', 'Asia/Dhaka'));
        $this->see('/');
        $this->travelTo(CarbonImmutable::parse('2026-11-12 10:00:00', 'Asia/Dhaka'));
        $this->see('/products');

        $this->artisan('visits:prune')->assertSuccessful();

        $this->assertSame(['/products'], DB::table('site_visits')->pluck('path')->all());
        $this->assertSame(['views' => 1, 'visitors' => 1], $this->totalsFor('2025-10-01'));
        $this->assertSame(['views' => 1, 'visitors' => 1], $this->totalsFor('2026-11-12'));
    }
}
