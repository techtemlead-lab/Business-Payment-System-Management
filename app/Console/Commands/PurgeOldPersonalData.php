<?php

namespace App\Console\Commands;

use App\Support\Audit;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Removes personal details that are no longer needed once a record has been closed for
 * longer than MADINA_PRIVACY_RETENTION_DAYS. It is run by hand only (never scheduled) and
 * only lists what it would change unless --force is given.
 *
 * Orders, payments, the ledger, commissions, withdrawals and the audit trail are never
 * touched: those are business and tax records that must be kept.
 */
#[Signature('privacy:purge {--force : Make the changes; without it the command only lists what it would do}')]
#[Description('Remove or blank out old personal data that is no longer needed (dry run unless --force)')]
class PurgeOldPersonalData extends Command
{
    /** Placeholder written over a person's name in blanked-out records. */
    public const RemovedName = '—';

    /** @var array<string, array{0: string, 1: string}> step => [what, action] */
    private const Steps = [
        'closed_enquiries' => ['Closed website enquiries', 'Blank out name, phone and message'],
        'rejected_applications' => ['Rejected partner applications', 'Blank out email and form details (role kept)'],
        'rejected_feedback' => ['Rejected website feedback', 'Blank out name, place and message'],
        'password_reset_tokens' => ['Old password-reset links', 'Delete'],
        'sessions' => ['Old login sessions (IP address and browser)', 'Delete'],
    ];

    public function handle(): int
    {
        $retentionDays = (int) config('madina.privacy.closed_record_retention_days', 0);
        if ($retentionDays < 1) {
            $this->error('Set MADINA_PRIVACY_RETENTION_DAYS to a number of days (1 or more) before running this command.');

            return self::FAILURE;
        }
        $cutoff = CarbonImmutable::now()->subDays($retentionDays);
        $counts = [];
        foreach (self::Steps as $step => [$what, $action]) {
            $counts[$step] = $this->records($step, $cutoff)->count();
            $this->line($what.': '.$counts[$step].' record(s) - '.$action);
        }

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was changed. Records closed before '.$cutoff->toDateString().' ('.$retentionDays.' days). Run again with --force to apply.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($cutoff): void {
            foreach (array_keys(self::Steps) as $step) {
                $this->clean($step, $cutoff);
            }
        });
        Audit::record(null, 'privacy.purged', 'retention', ['retention_days' => $retentionDays, 'cutoff' => $cutoff->toIso8601String(), 'counts' => $counts]);
        $this->info('Done. '.array_sum($counts).' record(s) cleaned; records closed before '.$cutoff->toDateString().'.');

        return self::SUCCESS;
    }

    /** Records one step still has to clean (already-cleaned rows are left out). */
    private function records(string $step, CarbonImmutable $cutoff): Builder
    {
        return match ($step) {
            'closed_enquiries' => DB::table('enquiries')
                ->where('status', 'closed')
                ->where('updated_at', '<', $cutoff)
                ->where(fn (Builder $query) => $query->where('phone', '<>', '')->orWhere('name', '<>', self::RemovedName)),
            'rejected_applications' => DB::table('dealer_applications')
                ->where('status', 'rejected')
                ->where('updated_at', '<', $cutoff)
                ->where('email', 'not like', '%@privacy.invalid'),
            'rejected_feedback' => DB::table('site_feedback')
                ->where('status', 'rejected')
                ->where('updated_at', '<', $cutoff)
                ->where('name', '<>', self::RemovedName),
            'password_reset_tokens' => DB::table('password_reset_tokens')->where('created_at', '<', $cutoff),
            'sessions' => DB::table('sessions')->where('last_activity', '<', $cutoff->getTimestamp()),
        };
    }

    private function clean(string $step, CarbonImmutable $cutoff): void
    {
        $records = $this->records($step, $cutoff);
        if ($step === 'closed_enquiries') {
            $blank = ['name' => self::RemovedName, 'phone' => '', 'message' => ''];
            if (Schema::hasColumn('enquiries', 'referred_by')) {
                $blank['referred_by'] = null;
            }
            $records->update($blank);
        } elseif ($step === 'rejected_applications') {
            foreach ($records->get(['id', 'data']) as $application) {
                $data = json_decode((string) $application->data, true);
                $role = is_array($data) && is_string($data['role'] ?? null) ? $data['role'] : 'dealer';
                DB::table('dealer_applications')->where('id', $application->id)->update([
                    'email' => 'removed-'.$application->id.'@privacy.invalid',
                    'data' => json_encode(['role' => $role, 'privacy_removed' => true], JSON_THROW_ON_ERROR),
                ]);
            }
        } elseif ($step === 'rejected_feedback') {
            $records->update(['name' => self::RemovedName, 'location' => '', 'message' => '']);
        } else {
            $records->delete();
        }
    }
}
