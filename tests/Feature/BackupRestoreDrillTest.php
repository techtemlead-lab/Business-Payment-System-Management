<?php

namespace Tests\Feature;

use App\Console\Commands\RestoreDrillApplicationBackup;
use App\Support\DeploymentReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * A backup is only proven by bringing it back. The drill restores the newest backup into a scratch database,
 * checks it against the live books, records the result for app:readiness, and removes the scratch copy.
 * pg_dump and pg_restore are replaced by fakes here; the scratch database itself is real.
 */
class BackupRestoreDrillTest extends TestCase
{
    use RefreshDatabase;

    /** What the fake pg_restore puts into the scratch database. */
    private bool $restoreBalancedJournal = true;

    private bool $restoreFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
        Storage::fake('backups');
        config(['madina.backup_disk' => 'backups', 'madina.backup_password' => str_repeat('Drill-backup-secret-', 3), 'madina.backup_offsite_disk' => '', 'madina.backups_enabled' => true]);
        Process::fake([
            '*pg_dump*' => function (PendingProcess $process) {
                $file = collect($process->command)->first(fn (string $part): bool => str_starts_with($part, '--file='));
                file_put_contents(substr((string) $file, 7), 'PGDMP fake custom-format dump');

                return Process::result();
            },
            '*pg_restore*--version*' => Process::result('pg_restore (PostgreSQL) 16.4'),
            '*pg_restore*--list*' => Process::result(";\n; Archive created\n1234; 0 16400 TABLE DATA public users madina\n"),
            '*pg_restore*--exit-on-error*' => fn () => $this->fakeRestore(),
        ]);
    }

    /** Stands in for pg_restore: builds an older copy of the books in the scratch database. */
    private function fakeRestore(): mixed
    {
        if ($this->restoreFails) {
            return Process::result(errorOutput: 'pg_restore: error: could not read input file', exitCode: 1);
        }
        $copy = DB::connection('restore_drill');
        $copy->statement('CREATE TABLE migrations (id serial PRIMARY KEY, migration varchar(255) NOT NULL, batch integer NOT NULL)');
        $copy->table('migrations')->insert(['migration' => DB::table('migrations')->orderBy('id')->value('migration'), 'batch' => 1]);
        $copy->statement('CREATE TABLE journal_lines (id serial PRIMARY KEY, debit numeric(14,2) NOT NULL DEFAULT 0, credit numeric(14,2) NOT NULL DEFAULT 0)');
        $copy->table('journal_lines')->insert([
            ['debit' => '500.00', 'credit' => '0.00'],
            ['debit' => '0.00', 'credit' => $this->restoreBalancedJournal ? '500.00' : '450.00'],
        ]);
        $copy->statement('CREATE TABLE users (id serial PRIMARY KEY)');
        $copy->statement('INSERT INTO users DEFAULT VALUES');

        return Process::result();
    }

    private function scratchDatabases(): array
    {
        return array_map(fn ($row): string => $row->datname, DB::select("SELECT datname FROM pg_database WHERE datname LIKE 'madina_restore_drill_%'"));
    }

    private function record(): array
    {
        return json_decode((string) Storage::disk('backups')->get(RestoreDrillApplicationBackup::RecordFile), true);
    }

    public function test_the_newest_backup_is_restored_checked_recorded_and_the_scratch_copy_removed(): void
    {
        $this->artisan('app:backup')->assertSuccessful();
        $backup = Storage::disk('backups')->files()[0];

        $this->artisan('app:backup-restore-drill')->assertSuccessful();

        $record = $this->record();
        $this->assertTrue($record['passed']);
        $this->assertSame($backup, $record['file']);
        $this->assertSame('0.00', $record['journal_difference']);
        $this->assertSame(1, $record['rows']['users']['backup']);
        $this->assertSame([], $this->scratchDatabases());
        $this->assertNotContains('restore_drill_overdue', app(DeploymentReadiness::class)->inspect()['warnings']);
        Process::assertRan(fn (PendingProcess $process): bool => in_array('--exit-on-error', (array) $process->command, true)
            && ! in_array(config('database.connections.pgsql.database'), (array) $process->command, true));
    }

    public function test_a_journal_that_does_not_balance_fails_the_drill_and_alerts(): void
    {
        $this->restoreBalancedJournal = false;
        $this->artisan('app:backup')->assertSuccessful();
        Log::spy();

        $this->artisan('app:backup-restore-drill')->assertFailed();

        $this->assertFalse($this->record()['passed']);
        $this->assertSame([], $this->scratchDatabases());
        Log::shouldHaveReceived('critical')->withArgs(fn (string $message): bool => str_contains($message, 'restore drill failed'))->once();
        $this->assertContains('restore_drill_overdue', app(DeploymentReadiness::class)->inspect()['warnings']);
    }

    public function test_a_dump_that_cannot_be_restored_fails_the_drill(): void
    {
        $this->restoreFails = true;
        $this->artisan('app:backup')->assertSuccessful();

        $this->artisan('app:backup-restore-drill')->assertFailed();

        $this->assertStringContainsString('could not read input file', $this->record()['pg_restore']);
        $this->assertSame([], $this->scratchDatabases());
    }

    public function test_without_any_backup_the_drill_says_so(): void
    {
        $this->artisan('app:backup-restore-drill')->expectsOutputToContain('There is no backup to restore')->assertFailed();
    }

    public function test_readiness_wants_a_recent_drill_and_an_offsite_copy_once_backups_are_on(): void
    {
        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertContains('restore_drill_overdue', $warnings);
        $this->assertContains('offsite_backup_not_configured', $warnings);

        Storage::disk('backups')->put(RestoreDrillApplicationBackup::RecordFile, json_encode(['passed' => true, 'ran_at' => now()->subDays(40)->toIso8601String()]));
        Storage::disk('backups')->put(DeploymentReadiness::OffsiteCopyRecord, json_encode(['passed' => true, 'ran_at' => now()->subHours(20)->toIso8601String()]));
        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertContains('restore_drill_overdue', $warnings);
        $this->assertNotContains('offsite_backup_not_configured', $warnings);

        config(['madina.backups_enabled' => false]);
        $warnings = app(DeploymentReadiness::class)->inspect()['warnings'];
        $this->assertNotContains('restore_drill_overdue', $warnings);
        $this->assertNotContains('offsite_backup_not_configured', $warnings);
    }
}
