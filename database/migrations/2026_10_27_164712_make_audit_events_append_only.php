<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Application role must not UPDATE/DELETE historical audit rows (M6).
 * INSERT remains allowed; privileged DB roles can still maintain the table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION madina_reject_audit_mutation()
RETURNS trigger
LANGUAGE plpgsql
AS $$
BEGIN
    RAISE EXCEPTION 'audit_events are append-only';
END;
$$;

DROP TRIGGER IF EXISTS audit_events_append_only_update ON audit_events;
DROP TRIGGER IF EXISTS audit_events_append_only_delete ON audit_events;

CREATE TRIGGER audit_events_append_only_update
    BEFORE UPDATE ON audit_events
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_audit_mutation();

CREATE TRIGGER audit_events_append_only_delete
    BEFORE DELETE ON audit_events
    FOR EACH ROW
    EXECUTE PROCEDURE madina_reject_audit_mutation();
SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS audit_events_append_only_update ON audit_events;
DROP TRIGGER IF EXISTS audit_events_append_only_delete ON audit_events;
DROP FUNCTION IF EXISTS madina_reject_audit_mutation();
SQL);
    }
};
