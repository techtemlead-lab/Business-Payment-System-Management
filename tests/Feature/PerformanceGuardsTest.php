<?php

namespace Tests\Feature;

use App\Console\Commands\PruneAccountNotices;
use App\Domain\Finance\AccountingStatements;
use App\Domain\Finance\JournalOpeningSnapshots;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Tests\TestCase;

class PerformanceGuardsTest extends TestCase
{
    use RefreshDatabase;

    private function notice(User $user, string $age, bool $read): void
    {
        DB::table('account_notices')->insert([
            'user_id' => $user->id, 'title' => 'Notice', 'body' => 'Body',
            'read_at' => $read ? now() : null,
            'created_at' => now()->sub($age), 'updated_at' => now()->sub($age),
        ]);
    }

    private function entry(string $when, string $account, string $debit, string $credit): void
    {
        $id = (string) Str::uuid();
        DB::table('journal_entries')->insert(['id' => $id, 'event_key' => 'perf.'.$id, 'description' => 'Test', 'created_at' => $when]);
        DB::table('journal_lines')->insert(['entry_id' => $id, 'account' => $account, 'debit' => $debit, 'credit' => $credit]);
    }

    public function test_notice_pruning_removes_old_read_notices_and_very_old_unread_ones_only(): void
    {
        $user = User::factory()->create();
        $this->notice($user, '200 days', true);
        $this->notice($user, '100 days', true);
        $this->notice($user, '200 days', false);
        $this->notice($user, '450 days', false);

        $this->artisan('notices:prune', ['--chunk' => 100])->expectsOutput('2 old notice(s) deleted.')->assertSuccessful();

        $this->assertSame(2, DB::table('account_notices')->count());
        $this->assertSame(1, DB::table('account_notices')->whereNotNull('read_at')->count());
    }

    public function test_notice_pruning_works_in_batches(): void
    {
        $user = User::factory()->create();
        foreach (range(1, 250) as $ignored) {
            $this->notice($user, '300 days', true);
        }

        $this->artisan('notices:prune', ['--chunk' => 100])->expectsOutput('250 old notice(s) deleted.')->assertSuccessful();

        $this->assertSame(0, DB::table('account_notices')->count());
    }

    public function test_notice_pruning_is_scheduled_daily(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->contains(fn ($event) => str_contains((string) $event->command, 'notices:prune'));

        $this->assertTrue($scheduled);
        $this->assertTrue(class_exists(PruneAccountNotices::class));
    }

    public function test_the_trial_balance_is_the_same_with_and_without_opening_snapshots(): void
    {
        $this->entry('2026-07-10 06:00:00', 'opening_balance_equity', '500.00', '0.00');
        $this->entry('2026-08-20 06:00:00', 'wallet_liability', '0.00', '500.00');
        $this->entry('2026-09-20 06:00:00', 'commission_expense', '120.50', '0.00');
        $this->entry('2026-10-05 06:00:00', 'commission_expense', '300.25', '0.00');
        $this->entry('2026-10-09 06:00:00', 'wallet_liability', '50.00', '0.00');

        $before = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31')['trial_balance'];

        $made = (new JournalOpeningSnapshots)->build(CarbonImmutable::parse('2026-11-18', 'UTC'));
        $this->assertSame(4, $made, 'August, September, October and November month starts');
        $this->assertSame(0, (new JournalOpeningSnapshots)->build(CarbonImmutable::parse('2026-11-18', 'UTC')), 'running it again adds nothing');

        $after = app(AccountingStatements::class)->build('2026-10-01', '2026-10-31')['trial_balance'];
        $this->assertSame($before, $after);
    }

    public function test_a_period_inside_a_month_uses_the_snapshot_and_still_matches(): void
    {
        $this->entry('2026-08-20 06:00:00', 'wallet_liability', '0.00', '500.00');
        $this->entry('2026-09-20 06:00:00', 'commission_expense', '120.50', '0.00');
        $this->entry('2026-10-05 06:00:00', 'commission_expense', '300.25', '0.00');
        $this->entry('2026-10-20 06:00:00', 'commission_expense', '10.00', '0.00');
        $plain = app(AccountingStatements::class)->build('2026-10-10', '2026-10-31')['trial_balance'];

        (new JournalOpeningSnapshots)->build(CarbonImmutable::parse('2026-11-18', 'UTC'));

        $this->assertSame($plain, app(AccountingStatements::class)->build('2026-10-10', '2026-10-31')['trial_balance']);
    }

    public function test_a_month_start_less_than_a_day_old_is_not_snapshotted_yet(): void
    {
        $this->entry('2026-10-20 06:00:00', 'commission_expense', '10.00', '0.00');

        $made = (new JournalOpeningSnapshots)->build(CarbonImmutable::parse('2026-11-01 05:00:00', 'UTC'));

        $this->assertSame(0, $made);
    }

    public function test_slow_queries_are_logged_without_their_bound_values(): void
    {
        Log::spy();

        DB::select('select pg_sleep(0.6), ? as secret', ['hunter2-bound-value']);

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context = []): bool => $message === 'Slow database query.'
            && ! str_contains(json_encode($context), 'hunter2-bound-value'))->once();
    }

    public function test_a_lazy_loaded_relationship_is_reported_outside_production(): void
    {
        Log::spy();
        User::factory()->count(2)->create();

        foreach (User::query()->limit(2)->get() as $user) {
            $user->sponsor;
        }

        Log::shouldHaveReceived('warning')->withArgs(fn (string $message): bool => str_starts_with($message, 'Lazy-loaded relationship'))->atLeast()->once();
    }

    public function test_the_hot_path_indexes_exist(): void
    {
        $indexes = DB::table('pg_indexes')->where('schemaname', 'public')->pluck('indexname')->all();

        foreach (['account_notices_user_id_created_at_index', 'journal_lines_user_id_id_index', 'prescription_lines_prescription_id_index', 'order_lines_dated_scheme_id_index'] as $name) {
            $this->assertContains($name, $indexes);
        }
    }
}
