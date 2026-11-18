<?php

namespace App\Console\Commands;

use App\Support\AuditChain;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VerifyAuditIntegrity extends Command
{
    protected $signature = 'audit:verify-integrity {--limit=1000 : Maximum number of recent events to analyze}';

    protected $description = 'Verify the cryptographic and sequential integrity of append-only audit logs';

    public function handle(AuditChain $chain): int
    {
        $this->info('Starting enterprise audit trail verification...');

        $totalEvents = DB::table('audit_events')->count();
        if ($totalEvents === 0) {
            $this->warn('No audit events found to verify.');

            return self::SUCCESS;
        }

        $this->line("Found {$totalEvents} historical audit records.");

        // 1. Verify PostgreSQL Database Triggers
        $driver = DB::connection()->getDriverName();
        if ($driver === 'pgsql') {
            $triggers = DB::table('information_schema.triggers')
                ->where('event_object_table', 'audit_events')
                ->pluck('trigger_name')
                ->all();
            // TRUNCATE triggers are statement-level and not listed in information_schema.triggers.
            $hasTruncateTrigger = DB::table('pg_trigger')->where('tgname', 'audit_events_no_truncate')->exists();

            $hasUpdateTrigger = in_array('audit_events_append_only_update', $triggers, true);
            $hasDeleteTrigger = in_array('audit_events_append_only_delete', $triggers, true);

            if ($hasUpdateTrigger && $hasDeleteTrigger && $hasTruncateTrigger) {
                $this->info('✓ Immutability DB triggers active (UPDATE, DELETE and TRUNCATE rejected).');
            } else {
                $this->error('✗ Missing append-only triggers on audit_events table!');

                return self::FAILURE;
            }
        }

        // 2. Validate JSON structure & Sequential ID Integrity
        $limit = (int) $this->option('limit');
        $events = DB::table('audit_events')
            ->orderBy('id', 'desc')
            ->limit($limit)
            ->get();

        $corruptedJson = 0;
        $invalidActions = 0;

        foreach ($events as $event) {
            if (empty($event->action) || empty($event->subject)) {
                $invalidActions++;
            }

            if (! empty($event->metadata)) {
                json_decode((string) $event->metadata);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $corruptedJson++;
                }
            }
        }

        if ($corruptedJson > 0 || $invalidActions > 0) {
            $this->error("Integrity anomalies detected! Corrupted metadata: {$corruptedJson}, Invalid actions: {$invalidActions}");

            return self::FAILURE;
        }

        // 3. Seal what is new, then walk the whole hash chain: a changed, removed or reordered row breaks it.
        $chain->seal();
        $result = $chain->verify();
        if (! $result['ok']) {
            $this->error("✗ Audit hash chain is broken at event #{$result['broken_at']} after {$result['checked']} sealed rows.");

            return self::FAILURE;
        }
        $anchor = $result['anchor'];
        // The anchor goes to the log every day; copy it off the server so a rewritten chain is caught.
        Log::notice('Audit chain anchor', ['seq' => $anchor['seq'], 'hash' => $anchor['hash']]);
        $this->info("✓ Hash chain intact over {$result['checked']} rows. Anchor: #{$anchor['seq']} {$anchor['hash']}");

        $this->info("✓ Successfully verified {$events->count()} most recent audit records. Zero anomalies detected.");

        return self::SUCCESS;
    }
}
