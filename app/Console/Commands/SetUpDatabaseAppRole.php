<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

/**
 * SA-04: the website should not sign in to PostgreSQL as the superuser that owns every table.
 * This creates (or refreshes) a limited login for the website: it may read and write rows, but it
 * owns nothing, cannot change the table layout, cannot TRUNCATE, and on the audit trail may only
 * add rows and fill in their seal. Run it with the owner login after each deploy's migrations;
 * tables created later by the owner get the same rights automatically.
 */
#[Signature('madina:db-app-role
    {--username=madina_app : Login name for the website}
    {--connection=pgsql_migrate : Connection that signs in as the table owner}
    {--no-password : Keep the current password (only refresh the rights)}')]
#[Description('Create or refresh the limited database login the website uses (not the table owner, no superuser rights).')]
class SetUpDatabaseAppRole extends Command
{
    public function handle(): int
    {
        $connection = DB::connection((string) $this->option('connection'));
        if ($connection->getDriverName() !== 'pgsql') {
            $this->error('Only PostgreSQL is supported.');

            return self::FAILURE;
        }
        $username = (string) $this->option('username');
        if (preg_match('/^[a-z_][a-z0-9_]{2,62}$/', $username) !== 1) {
            $this->error('Use a lower-case login name of letters, digits and _.');

            return self::FAILURE;
        }
        $owner = (string) $connection->selectOne('SELECT current_user AS name')->name;
        if ($owner === $username) {
            $this->error('The website login must not be the table owner. Run this with the owner login (DB_MIGRATE_USERNAME).');

            return self::FAILURE;
        }
        $password = (string) config('database.app_role.password', '');
        if (! $this->option('no-password') && strlen($password) < 24) {
            $this->error('Set DB_APP_PASSWORD (at least 24 characters) in the environment first, or pass --no-password.');

            return self::FAILURE;
        }

        $role = $this->quoteIdentifier($username);
        $ownerRole = $this->quoteIdentifier($owner);
        $database = $this->quoteIdentifier((string) $connection->getDatabaseName());
        $connection->transaction(function () use ($connection, $username, $role, $ownerRole, $database, $password): void {
            if (! $connection->table('pg_roles')->where('rolname', $username)->exists()) {
                $connection->unprepared("CREATE ROLE {$role} LOGIN");
            }
            $connection->unprepared("ALTER ROLE {$role} WITH LOGIN NOSUPERUSER NOCREATEDB NOCREATEROLE NOREPLICATION NOBYPASSRLS");
            if (! $this->option('no-password')) {
                $connection->unprepared("ALTER ROLE {$role} WITH PASSWORD ".$this->quoteLiteral($connection, $password));
            }
            foreach ([
                "GRANT CONNECT ON DATABASE {$database} TO {$role}",
                "GRANT USAGE ON SCHEMA public TO {$role}",
                "GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}",
                "REVOKE TRUNCATE, REFERENCES, TRIGGER ON ALL TABLES IN SCHEMA public FROM {$role}",
                "GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO {$role}",
                "ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerRole} IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO {$role}",
                "ALTER DEFAULT PRIVILEGES FOR ROLE {$ownerRole} IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO {$role}",
                // The audit trail: add rows and seal them, nothing else.
                "REVOKE UPDATE, DELETE ON audit_events FROM {$role}",
                "GRANT UPDATE (chain_seq, prev_hash, row_hash) ON audit_events TO {$role}",
                // The migrations table belongs to deploys only.
                "REVOKE INSERT, UPDATE, DELETE ON migrations FROM {$role}",
            ] as $statement) {
                $connection->unprepared($statement);
            }
        });
        $this->info("The website login {$username} is ready. Set DB_USERNAME={$username} and DB_PASSWORD to DB_APP_PASSWORD for the app, queue and scheduler.");

        return self::SUCCESS;
    }

    private function quoteIdentifier(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }

    private function quoteLiteral(Connection $connection, string $value): string
    {
        return $connection->getPdo()->quote($value);
    }
}
