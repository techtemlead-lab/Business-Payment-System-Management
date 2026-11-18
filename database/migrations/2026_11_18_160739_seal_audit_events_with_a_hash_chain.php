<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SA-04: the audit trail could still be emptied with TRUNCATE, and nothing showed a changed row.
 *
 * - A statement trigger refuses TRUNCATE.
 * - Each row gets a place in a hash chain (chain_seq, prev_hash, row_hash), filled in by
 *   `audit:seal` every minute. A row may be updated only to fill these three empty columns once;
 *   every other UPDATE and every DELETE is still refused.
 * - `audit:verify-integrity` walks the chain, so a rewritten or removed row breaks it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('chain_seq')->nullable()->unique();
            $table->char('prev_hash', 64)->nullable();
            $table->char('row_hash', 64)->nullable();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION madina_audit_allow_seal_only()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    IF OLD.row_hash IS NULL
        AND NEW.row_hash IS NOT NULL
        AND NEW.chain_seq IS NOT NULL
        AND NEW.prev_hash IS NOT NULL
        AND NEW.id = OLD.id
        AND NEW.actor_id IS NOT DISTINCT FROM OLD.actor_id
        AND NEW.action = OLD.action
        AND NEW.subject = OLD.subject
        AND NEW.metadata::text IS NOT DISTINCT FROM OLD.metadata::text
        AND NEW.created_at IS NOT DISTINCT FROM OLD.created_at
    THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION 'audit_events are append-only';
END;
$$;

DROP TRIGGER IF EXISTS audit_events_append_only_update ON audit_events;
CREATE TRIGGER audit_events_append_only_update
    BEFORE UPDATE ON audit_events
    FOR EACH ROW
    EXECUTE PROCEDURE madina_audit_allow_seal_only();

CREATE OR REPLACE FUNCTION madina_reject_audit_truncate()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit_events cannot be truncated';
END;
$$;

DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events;
CREATE TRIGGER audit_events_no_truncate
    BEFORE TRUNCATE ON audit_events
    FOR EACH STATEMENT
    EXECUTE PROCEDURE madina_reject_audit_truncate();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_events_no_truncate ON audit_events;
DROP FUNCTION IF EXISTS madina_reject_audit_truncate();
DROP TRIGGER IF EXISTS audit_events_append_only_update ON audit_events;
CREATE TRIGGER audit_events_append_only_update
    BEFORE UPDATE ON audit_events
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_audit_mutation();
DROP FUNCTION IF EXISTS madina_audit_allow_seal_only();
-- The seal columns are dropped below; that ALTER does not fire row triggers.
SQL);
        }

        Schema::table('audit_events', function (Blueprint $table): void {
            $table->dropUnique(['chain_seq']);
            $table->dropColumn(['chain_seq', 'prev_hash', 'row_hash']);
        });
    }
};
