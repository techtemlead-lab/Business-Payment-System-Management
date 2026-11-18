<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Trigram indexes so the staff and partner searches that use ILIKE '%text%' stay fast once there are
 * tens of thousands of partners and orders (PQ-05).
 *
 * Everything is guarded: when the pg_trgm extension is not available, or the database user may not
 * create it, the migration logs a warning and does nothing. Searches still work, only slower.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /** @var array<string, string> */
    private array $indexes = [
        'users_name_trgm_index' => 'users USING gin (name gin_trgm_ops)',
        'users_dealer_code_trgm_index' => 'users USING gin (dealer_code gin_trgm_ops)',
        'users_email_trgm_index' => 'users USING gin (email gin_trgm_ops)',
        'users_phone_trgm_index' => 'users USING gin (phone gin_trgm_ops)',
        'orders_number_trgm_index' => 'orders USING gin (number gin_trgm_ops)',
        'products_name_trgm_index' => 'products USING gin (name gin_trgm_ops)',
        'products_sku_trgm_index' => 'products USING gin (sku gin_trgm_ops)',
    ];

    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql' || ! $this->trigramAvailable()) {
            return;
        }
        foreach ($this->indexes as $name => $definition) {
            $table = strtok($definition, ' ');
            $column = $this->column($definition);
            if (! DB::table('information_schema.columns')->where('table_schema', 'public')->where('table_name', $table)->where('column_name', $column)->exists()) {
                continue;
            }
            DB::statement("CREATE INDEX {$this->concurrently()}IF NOT EXISTS {$name} ON {$definition}");
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }
        foreach (array_keys($this->indexes) as $name) {
            DB::statement("DROP INDEX {$this->concurrently()}IF EXISTS {$name}");
        }
    }

    private function trigramAvailable(): bool
    {
        try {
            if (! DB::table('pg_available_extensions')->where('name', 'pg_trgm')->exists()) {
                Log::warning('pg_trgm is not available on this PostgreSQL server; search indexes were skipped.');

                return false;
            }
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

            return true;
        } catch (Throwable $e) {
            Log::warning('pg_trgm could not be enabled; search indexes were skipped: '.$e->getMessage());

            return false;
        }
    }

    private function column(string $definition): string
    {
        preg_match('/\((\w+) gin_trgm_ops\)/', $definition, $match);

        return $match[1];
    }

    private function concurrently(): string
    {
        return DB::transactionLevel() === 0 ? 'CONCURRENTLY ' : '';
    }
};
