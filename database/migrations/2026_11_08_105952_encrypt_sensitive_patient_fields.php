<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypts the existing sensitive patient fields in place, for the "encrypted" casts on the
 * models. It runs in one transaction, so a failure leaves every row as it was, and it can be
 * run again: a value that is already encrypted is left alone. Patient names stay readable so
 * that search keeps working.
 */
return new class extends Migration
{
    /** @var array<string, list<string>> */
    private const Columns = [
        'prescription_patients' => ['phone', 'notes'],
        'prescriptions' => ['complaints', 'diagnosis', 'advice'],
        'prescription_templates' => ['advice'],
        'consultation_requests' => ['phone', 'problem'],
    ];

    public function up(): void
    {
        // Encrypted text is far longer than the 20 characters a phone number needed.
        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->text('phone')->nullable()->change();
        });
        Schema::table('consultation_requests', function (Blueprint $table): void {
            $table->text('phone')->change();
        });

        foreach (self::Columns as $table => $columns) {
            $this->rewrite($table, $columns, fn (string $value): ?string => $this->decrypted($value) === null ? Crypt::encryptString($value) : null);
        }
    }

    public function down(): void
    {
        foreach (self::Columns as $table => $columns) {
            $this->rewrite($table, $columns, fn (string $value): ?string => $this->decrypted($value));
        }

        Schema::table('prescription_patients', function (Blueprint $table): void {
            $table->string('phone', 20)->nullable()->change();
        });
        Schema::table('consultation_requests', function (Blueprint $table): void {
            $table->string('phone', 20)->change();
        });
    }

    /**
     * Writes back each value $convert changes. Null from $convert means leave it alone, and
     * timestamps are not touched.
     *
     * @param  list<string>  $columns
     * @param  callable(string): ?string  $convert
     */
    private function rewrite(string $table, array $columns, callable $convert): void
    {
        DB::table($table)->select(['id', ...$columns])->chunkById(500, function ($rows) use ($table, $columns, $convert): void {
            foreach ($rows as $row) {
                $changes = [];
                foreach ($columns as $column) {
                    $converted = $row->{$column} === null ? null : $convert((string) $row->{$column});
                    if ($converted !== null) {
                        $changes[$column] = $converted;
                    }
                }
                if ($changes !== []) {
                    DB::table($table)->where('id', $row->id)->update($changes);
                }
            }
        });
    }

    /** The plain value, or null when $value is not something this app encrypted. */
    private function decrypted(string $value): ?string
    {
        try {
            return Crypt::decryptString($value);
        } catch (DecryptException) {
            return null;
        }
    }
};
