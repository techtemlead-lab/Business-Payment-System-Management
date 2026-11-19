<?php

namespace Tests\Feature;

use App\Domain\Audience\PartnerActivity;
use App\Domain\Audience\UserAgentParser;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** CR-17: what is recorded for dealers and sub-dealers, and the owner-only list on the analysis page. */
class PartnerActivityTest extends TestCase
{
    use RefreshDatabase;

    private const Windows = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36';

    private const Android = 'Mozilla/5.0 (Linux; Android 13; SM-A135F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Mobile Safari/537.36';

    private const IPhone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'Asia/Dhaka'));
    }

    /** @return array<string, array{string, string, string|null, string|null}> */
    public static function agents(): array
    {
        return [
            'Windows Chrome' => [self::Windows, 'pc', 'Chrome', 'Windows'],
            'Windows Edge' => ['Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36 Edg/130.0.0.0', 'pc', 'Edge', 'Windows'],
            'Android Chrome' => [self::Android, 'phone', 'Chrome', 'Android'],
            'Android tablet' => ['Mozilla/5.0 (Linux; Android 12; SM-T500) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36', 'tablet', 'Chrome', 'Android'],
            'iPhone Safari' => [self::IPhone, 'phone', 'Safari', 'iOS'],
            'iPad' => ['Mozilla/5.0 (iPad; CPU OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1', 'tablet', 'Safari', 'iOS'],
            'Samsung phone' => ['Mozilla/5.0 (Linux; Android 13; SM-S918B) AppleWebKit/537.36 (KHTML, like Gecko) SamsungBrowser/24.0 Chrome/117.0.0.0 Mobile Safari/537.36', 'phone', 'Samsung Internet', 'Android'],
            'Firefox Linux' => ['Mozilla/5.0 (X11; Linux x86_64; rv:130.0) Gecko/20100101 Firefox/130.0', 'pc', 'Firefox', 'Linux'],
            'no user agent' => ['', 'pc', null, null],
        ];
    }

    #[DataProvider('agents')]
    public function test_the_parser_tells_phone_from_computer(string $agent, string $kind, ?string $browser, ?string $os): void
    {
        $this->assertSame(['device_kind' => $kind, 'browser' => $browser, 'os' => $os], UserAgentParser::parse($agent));
    }

    public function test_a_partner_request_records_ip_device_and_browser(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->withServerVariables(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => self::Android])->get('/')->assertOk();

        $row = DB::table('partner_activity')->where('user_id', $dealer->id)->first();
        $this->assertNotNull($row);
        $this->assertSame('203.0.113.9', $row->last_ip);
        $this->assertSame('phone', $row->device_kind);
        $this->assertSame('Chrome', $row->browser);
        $this->assertSame('Android', $row->os);
    }

    public function test_writes_are_throttled_to_once_a_minute(): void
    {
        $dealer = User::factory()->subDealer()->create();
        $this->actingAs($dealer)->onPortal('subdealer');

        $this->get('/')->assertOk();
        $first = DB::table('partner_activity')->where('user_id', $dealer->id)->value('last_seen_at');

        $this->travelTo(now()->addSeconds(30));
        $this->get('/')->assertOk();
        $this->assertSame($first, DB::table('partner_activity')->where('user_id', $dealer->id)->value('last_seen_at'), 'no write inside 60 seconds');

        $this->travelTo(now()->addSeconds(40));
        $this->get('/')->assertOk();
        $this->assertNotSame($first, DB::table('partner_activity')->where('user_id', $dealer->id)->value('last_seen_at'), 'a write after a minute');
        $this->assertSame(1, DB::table('partner_activity')->where('user_id', $dealer->id)->count(), 'one row per partner, never a history');
    }

    public function test_office_staff_are_not_recorded(): void
    {
        $staff = User::factory()->staff('super_admin')->create();

        $this->actingAs($staff)->onPortal('sadmin')->get('/visitors')->assertOk();

        $this->assertSame(0, DB::table('partner_activity')->count());
    }

    public function test_signing_in_stores_the_sign_in_time(): void
    {
        $dealer = User::factory()->dealer()->create();
        $request = Request::create('/login', 'POST', server: ['REMOTE_ADDR' => '198.51.100.4', 'HTTP_USER_AGENT' => self::Windows]);
        $request->setLaravelSession($this->app['session.store']);

        app(PartnerActivity::class)->signedIn($request, $dealer);

        $row = DB::table('partner_activity')->where('user_id', $dealer->id)->first();
        $this->assertNotNull($row->last_login_at);
        $this->assertSame('pc', $row->device_kind);
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $query = ''): array
    {
        $owner = User::factory()->staff('super_admin')->create();
        $response = $this->actingAs($owner)->onPortal('sadmin')->get('/visitors'.$query)->assertOk();

        return $response->viewData('page')['props']['data']['visitors']['partner_activity'];
    }

    private function seen(User $user, string $when, string $ip = '203.0.113.1', string $device = 'pc'): void
    {
        DB::table('partner_activity')->insert(['user_id' => $user->id, 'last_seen_at' => CarbonImmutable::parse($when, 'Asia/Dhaka')->setTimezone(config('app.timezone')), 'last_ip' => $ip, 'device_kind' => $device, 'browser' => 'Chrome', 'os' => 'Windows', 'last_login_at' => CarbonImmutable::parse($when, 'Asia/Dhaka')->setTimezone(config('app.timezone'))]);
    }

    public function test_online_means_active_in_the_last_five_minutes_and_counts_split_by_type(): void
    {
        $dealerNow = User::factory()->dealer()->create(['name' => 'Rahim Dealer']);
        $dealerOld = User::factory()->dealer()->create(['name' => 'Karim Dealer']);
        $sub = User::factory()->subDealer()->create(['name' => 'Salma Sub']);
        $this->seen($dealerNow, '2026-11-19 11:58:00');
        $this->seen($dealerOld, '2026-11-19 11:50:00');
        $this->seen($sub, '2026-11-19 11:56:00');

        $page = $this->page();

        $this->assertSame(['dealers' => 1, 'sub_dealers' => 1], $page['counts']);
        $byName = collect($page['rows'])->keyBy('name');
        $this->assertTrue($byName['Rahim Dealer']['online']);
        $this->assertFalse($byName['Karim Dealer']['online']);
        $this->assertSame('203.0.113.1', $byName['Rahim Dealer']['ip']);
        $this->assertSame('Rahim Dealer', $page['rows'][0]['name'], 'most recently active first');
    }

    public function test_filters_search_and_paging(): void
    {
        $online = User::factory()->dealer()->create(['name' => 'Online Dealer']);
        $week = User::factory()->subDealer()->create(['name' => 'Week Sub']);
        $recent = User::factory()->dealer()->create(['name' => 'Recent Dealer', 'phone' => '01711000222']);
        $never = User::factory()->dealer()->create(['name' => 'Never Dealer']);
        $this->seen($online, '2026-11-19 11:59:00');
        $this->seen($week, '2026-11-10 10:00:00');
        $this->seen($recent, '2026-11-18 10:00:00');

        $names = fn (string $q): array => collect($this->page($q)['rows'])->pluck('name')->sort()->values()->all();

        $this->assertSame(['Never Dealer', 'Online Dealer', 'Recent Dealer', 'Week Sub'], $names(''));
        $this->assertSame(['Never Dealer', 'Online Dealer', 'Recent Dealer'], $names('?status=dealers'));
        $this->assertSame(['Week Sub'], $names('?status=sub_dealers'));
        $this->assertSame(['Online Dealer'], $names('?status=online'));
        $this->assertSame(['Never Dealer', 'Week Sub'], $names('?status=idle'));
        $this->assertSame(['Recent Dealer'], $names('?q=01711000'));
        $this->assertSame(['Week Sub'], $names('?q='.urlencode($week->dealer_code)));

        $paged = $this->page('?page=99');
        $this->assertSame(1, $paged['page']);
        $this->assertSame(4, $paged['total']);
    }

    public function test_only_owners_can_open_the_list(): void
    {
        $dealer = User::factory()->dealer()->create();
        $this->seen($dealer, '2026-11-19 11:59:00', '203.0.113.77');

        $this->actingAs(User::factory()->staff('employee')->create())->onPortal('manage')->get('/visitors')->assertNotFound();
        $this->actingAs(User::factory()->staff('employee')->create())->onPortal('sadmin')->get('/visitors')->assertStatus(403);

        $partnerResponse = $this->actingAs(User::factory()->dealer()->create())->onPortal('dealer')->get('/visitors');
        $this->assertContains($partnerResponse->getStatusCode(), [403, 404, 302]);
        $this->assertStringNotContainsString('203.0.113.77', $partnerResponse->getContent());
    }
}
