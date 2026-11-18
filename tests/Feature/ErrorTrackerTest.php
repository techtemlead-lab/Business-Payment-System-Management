<?php

namespace Tests\Feature;

use App\Domain\Diagnostics\ErrorTracker;
use App\Domain\Diagnostics\ErrorTrackingSettings;
use App\Domain\Staff\LeadershipAccess;
use App\Models\User;
use App\Support\DeploymentReadiness;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ErrorTrackerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Cache::flush();
        // D11 (SA-07): the MD and Chairman open owner pages such as this one once the Super Admin grants them.
        LeadershipAccess::save(User::factory()->staff('super_admin')->create(), ['md' => ['owner_tools'], 'chairman' => ['owner_tools']], 0);
    }

    private function tracker(): ErrorTracker
    {
        return app(ErrorTracker::class);
    }

    private function boom(string $message = 'Something broke'): RuntimeException
    {
        return new RuntimeException($message);
    }

    /** @return array<string, array{string}> */
    public static function owners(): array
    {
        return ['Super Admin' => ['super_admin'], 'MD' => ['md'], 'Chairman' => ['chairman']];
    }

    public function test_an_unhandled_exception_is_captured_with_where_it_happened(): void
    {
        Route::get('/__boom', fn () => throw new RuntimeException('Order sync failed'))->name('test.boom');
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->get('/__boom')->assertStatus(500);

        $row = DB::table('error_reports')->first();
        $this->assertNotNull($row);
        $this->assertSame(RuntimeException::class, $row->exception_class);
        $this->assertSame('Order sync failed', $row->message);
        $this->assertSame('tests/Feature/ErrorTrackerTest.php', $row->file);
        $this->assertSame('test.boom', $row->route_name);
        $this->assertSame('public', $row->portal);
        $this->assertSame(500, (int) $row->http_status);
        $this->assertSame($owner->id, (int) $row->user_id);
        $this->assertSame(1, (int) $row->occurrences);
        $this->assertNull($row->fixed_at);
    }

    public function test_pages_that_are_missing_or_forbidden_are_not_logged(): void
    {
        $this->get('/no-such-page-anywhere')->assertNotFound();

        $this->assertSame(0, DB::table('error_reports')->count());
    }

    public function test_the_same_problem_is_one_row_that_counts_up(): void
    {
        $exception = $this->boom();
        $this->tracker()->capture($exception);
        $this->travel(61)->seconds();
        $this->tracker()->capture($exception);
        $this->travel(61)->seconds();
        $this->tracker()->capture($exception);

        $this->assertSame(1, DB::table('error_reports')->count());
        $row = DB::table('error_reports')->first();
        $this->assertSame(3, (int) $row->occurrences);
        $this->assertSame(sha1(RuntimeException::class.'|tests/Feature/ErrorTrackerTest.php|'.$exception->getLine()), $row->fingerprint);
    }

    public function test_a_loop_of_the_same_error_touches_the_table_once(): void
    {
        $exception = $this->boom();
        for ($i = 0; $i < 50; $i++) {
            $this->tracker()->capture($exception);
        }

        $this->assertSame(1, (int) DB::table('error_reports')->value('occurrences'));
    }

    public function test_a_flood_of_different_errors_cannot_fill_the_table(): void
    {
        for ($i = 0; $i < ErrorTracker::NewRowsPerMinute + 20; $i++) {
            $class = 'FloodError'.$i;
            eval("class {$class} extends \\RuntimeException {}");
            $this->tracker()->capture(new $class('flood'));
        }

        $this->assertSame(ErrorTracker::NewRowsPerMinute, DB::table('error_reports')->count());
    }

    public function test_nothing_from_the_request_is_kept(): void
    {
        Route::post('/__leak', fn () => throw new RuntimeException('Callback failed: https://example.test/cb?token=SECRETQUERY&x=1 for amy@example.test'))->name('test.leak');
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)
            ->withHeaders(['Authorization' => 'Bearer BEARERSECRET', 'Cookie' => 'remember=COOKIESECRET'])
            ->post('/__leak?secret=SECRETQUERY', ['password' => 'hunter2-SECRETBODY', 'otp' => '123456'])
            ->assertStatus(500);

        $stored = json_encode(DB::table('error_reports')->get(), JSON_THROW_ON_ERROR);
        foreach (['SECRETQUERY', 'hunter2', 'SECRETBODY', 'BEARERSECRET', 'COOKIESECRET', '123456', 'amy@example.test', '__leak'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, $secret);
        }
        $this->assertStringContainsString('[redacted]', $stored);
        $this->assertSame('test.leak', DB::table('error_reports')->value('route_name'));
    }

    public function test_a_database_error_message_drops_the_query_and_its_values(): void
    {
        $this->tracker()->capture($this->boom('SQLSTATE[23505]: Unique violation (Connection: pgsql, SQL: insert into "users" ("phone") values (01712345678))'));

        $this->assertSame('SQLSTATE[23505]: Unique violation', DB::table('error_reports')->value('message'));
    }

    public function test_a_failure_inside_the_tracker_never_reaches_the_request(): void
    {
        Cache::shouldReceive('add')->andThrow(new RuntimeException('cache broke'));

        $this->tracker()->capture($this->boom());

        $this->assertTrue(true);
    }

    public function test_nothing_is_kept_while_tracking_is_off(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        app(ErrorTrackingSettings::class)->save($owner, false, 90, 0);

        $this->tracker()->capture($this->boom());

        $this->assertSame(0, DB::table('error_reports')->count());
    }

    public function test_a_fixed_problem_that_comes_back_is_open_again(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $exception = $this->boom();
        $this->tracker()->capture($exception);
        $id = (int) DB::table('error_reports')->value('id');

        $this->actingAs($owner)->onPortal('sadmin')->post("/commands/errors/{$id}/fixed")->assertRedirect();
        $this->assertNotNull(DB::table('error_reports')->value('fixed_at'));
        $this->assertTrue(DB::table('audit_events')->where('action', 'errors.marked_fixed')->where('actor_id', $owner->id)->exists());

        $this->travel(61)->seconds();
        $this->tracker()->capture($exception);

        $this->assertNull(DB::table('error_reports')->value('fixed_at'));
        $this->assertSame(2, (int) DB::table('error_reports')->value('occurrences'));
    }

    #[DataProvider('owners')]
    public function test_each_owner_can_open_the_page_and_a_problem(string $role): void
    {
        $this->tracker()->capture($this->boom('Visible problem'));
        $id = (int) DB::table('error_reports')->value('id');
        $owner = User::factory()->staff($role)->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/errors')->assertOk()
            ->assertInertia(fn ($page) => $page->where('section', 'errors')->where('data.errors.counts.open', 1)->where('data.errors.rows.data.0.message', 'Visible problem'));
        $this->actingAs($owner)->onPortal('sadmin')->get('/errors?error='.$id)->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.errors.detail.id', $id));
    }

    public function test_staff_and_partners_cannot_open_or_change_the_log(): void
    {
        $this->tracker()->capture($this->boom());
        $id = (int) DB::table('error_reports')->value('id');

        foreach (['finance', 'content', 'account_manager'] as $role) {
            $staff = User::factory()->staff($role)->create();
            $this->actingAs($staff)->onPortal('manage')->get('/errors')->assertNotFound();
            $this->actingAs($staff)->onPortal('sadmin')->get('/errors')->assertForbidden();
            $this->actingAs($staff)->onPortal('sadmin')->post("/commands/errors/{$id}/fixed")->assertForbidden();
            $this->actingAs($staff)->onPortal('sadmin')->post('/commands/owner/error-tracking', ['enabled' => '0', 'retention_days' => 30, 'version' => 0])->assertForbidden();
        }
        $dealer = User::factory()->create(['role' => 'dealer']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/errors')->assertNotFound();
        $this->assertNull(DB::table('error_reports')->value('fixed_at'));
    }

    public function test_the_list_searches_filters_and_sorts(): void
    {
        $now = now();
        $base = ['file' => 'app/X.php', 'line' => 1, 'http_status' => 500, 'created_at' => $now, 'updated_at' => $now];
        DB::table('error_reports')->insert([
            [...$base, 'fingerprint' => 'a', 'exception_class' => 'App\\PaymentError', 'message' => 'Gateway timed out', 'occurrences' => 2, 'first_seen_at' => $now->copy()->subDays(5), 'last_seen_at' => $now->copy()->subDay(), 'fixed_at' => null],
            [...$base, 'fingerprint' => 'b', 'exception_class' => 'App\\StockError', 'message' => 'Lot missing', 'occurrences' => 9, 'first_seen_at' => $now->copy()->subDays(9), 'last_seen_at' => $now->copy()->subHours(3), 'fixed_at' => null],
            [...$base, 'fingerprint' => 'c', 'exception_class' => 'App\\OldError', 'message' => 'Already handled', 'occurrences' => 1, 'first_seen_at' => $now->copy()->subDays(9), 'last_seen_at' => $now->copy()->subDays(2), 'fixed_at' => $now],
        ]);
        $owner = User::factory()->staff('super_admin')->create();
        $get = fn (string $query): array => array_column($this->actingAs($owner)->onPortal('sadmin')->get('/errors'.$query)->assertOk()->viewData('page')['props']['data']['errors']['rows']['data'], 'message');

        $this->assertSame(['Lot missing', 'Gateway timed out'], $get(''));
        $this->assertSame(['Gateway timed out', 'Lot missing'], $get('?sort=first_seen'));
        $this->assertSame(['Lot missing', 'Gateway timed out'], $get('?sort=count'));
        $this->assertSame(['Already handled'], $get('?status=fixed'));
        $this->assertCount(3, $get('?status=all'));
        $this->assertSame(['Gateway timed out'], $get('?q=payment'));
        $this->actingAs($owner)->onPortal('sadmin')->get('/errors?sort=drop')->assertSessionHasErrors('sort');
    }

    public function test_the_owner_changes_the_switch_and_days_with_an_audit_line(): void
    {
        $owner = User::factory()->staff('md')->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/error-tracking', ['enabled' => '1', 'retention_days' => 30, 'version' => 0])->assertRedirect();

        $this->assertSame(30, app(ErrorTrackingSettings::class)->retentionDays());
        $this->assertTrue(DB::table('audit_events')->where('action', 'errors.tracking_changed')->exists());
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/error-tracking', ['enabled' => '1', 'retention_days' => 3, 'version' => 1])->assertSessionHasErrors('retention_days');
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/owner/error-tracking', ['enabled' => '1', 'retention_days' => 40, 'version' => 0])->assertSessionHasErrors('version');
    }

    public function test_pruning_deletes_only_problems_older_than_the_retention_days(): void
    {
        $now = now();
        $base = ['file' => 'app/X.php', 'line' => 1, 'http_status' => 500, 'occurrences' => 1, 'created_at' => $now, 'updated_at' => $now, 'first_seen_at' => $now->copy()->subDays(200)];
        DB::table('error_reports')->insert([
            [...$base, 'fingerprint' => 'old', 'exception_class' => 'E', 'message' => 'm', 'last_seen_at' => $now->copy()->subDays(100)],
            [...$base, 'fingerprint' => 'mid', 'exception_class' => 'E', 'message' => 'm', 'last_seen_at' => $now->copy()->subDays(40)],
            [...$base, 'fingerprint' => 'new', 'exception_class' => 'E', 'message' => 'm', 'last_seen_at' => $now->copy()->subDays(2)],
        ]);

        $this->artisan('errors:prune')->assertSuccessful();
        $this->assertEqualsCanonicalizing(['mid', 'new'], DB::table('error_reports')->pluck('fingerprint')->all());

        app(ErrorTrackingSettings::class)->save(User::factory()->staff('super_admin')->create(), true, 30, 0);
        $this->artisan('errors:prune')->assertSuccessful();
        $this->assertSame(['new'], DB::table('error_reports')->pluck('fingerprint')->all());
    }

    public function test_the_prune_command_is_scheduled(): void
    {
        $this->assertStringContainsString('errors:prune', collect(app(Schedule::class)->events())->map->command->implode(' '));
    }

    public function test_readiness_stops_warning_when_tracking_is_on(): void
    {
        config(['app.env' => 'production', 'sentry.dsn' => null]);
        $this->assertNotContains('error_tracking_not_configured', app(DeploymentReadiness::class)->inspect()['warnings']);

        app(ErrorTrackingSettings::class)->save(User::factory()->staff('super_admin')->create(), false, 90, 0);
        $this->assertContains('error_tracking_not_configured', app(DeploymentReadiness::class)->inspect()['warnings']);
    }
}
