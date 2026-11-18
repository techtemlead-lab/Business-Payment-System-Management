<?php

use App\Casts\ProtectedProfile;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * LC-05: encrypt date of birth, parents' names, village, post office and birth-certificate number
 * inside every existing account profile. Safe to run again: encrypted values are left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->rewrite(fn (array $profile): array => ProtectedProfile::protect($profile));
    }

    public function down(): void
    {
        $this->rewrite(fn (array $profile): array => ProtectedProfile::reveal($profile));
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private function rewrite(callable $change): void
    {
        DB::table('users')->whereNotNull('profile')->orderBy('id')->select(['id', 'profile'])
            ->chunkById(500, function ($rows) use ($change): void {
                foreach ($rows as $row) {
                    $profile = json_decode((string) $row->profile, true);
                    if (! is_array($profile)) {
                        continue;
                    }
                    $next = $change($profile);
                    if ($next !== $profile) {
                        DB::table('users')->where('id', $row->id)->update(['profile' => json_encode($next, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }
};
