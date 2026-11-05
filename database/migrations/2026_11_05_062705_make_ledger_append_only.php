<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Journal entries and lines are the money ledger. Corrections are new
 * entries, never edits, so the application role may only insert rows.
 * Same protection as audit_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION madina_reject_ledger_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION '% is append-only: post a correcting entry instead', TG_TABLE_NAME;
END;
$$;

DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;
DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines;

CREATE TRIGGER journal_entries_append_only
    BEFORE UPDATE OR DELETE ON journal_entries
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_ledger_mutation();

CREATE TRIGGER journal_lines_append_only
    BEFORE UPDATE OR DELETE ON journal_lines
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_ledger_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS journal_entries_append_only ON journal_entries;
DROP TRIGGER IF EXISTS journal_lines_append_only ON journal_lines;
DROP FUNCTION IF EXISTS madina_reject_ledger_mutation();
SQL);
    }
};
