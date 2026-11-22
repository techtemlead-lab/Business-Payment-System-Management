<?php

use App\Domain\Accounts\IdentityKeys;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One person, one account (client, 2026-11-22). The NID number and the birth-certificate number are stored
     * encrypted, so each account also keeps a one-way key of each (see IdentityKeys) that can be compared. The keys
     * are indexed, not unique: accounts that already share a number keep working, and the rule is enforced when an
     * account is created or its number is changed.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('nid_hash', 64)->nullable()->index();
            $table->string('birth_certificate_hash', 64)->nullable()->index();
        });

        // Keys for the accounts that already exist. A row whose number cannot be read is left without a key.
        User::query()->orderBy('id')->chunkById(200, function ($users): void {
            foreach ($users as $user) {
                try {
                    $profile = $user->profile ?? [];
                    DB::table('users')->where('id', $user->id)->update([
                        'nid_hash' => IdentityKeys::key(IdentityKeys::Nid, $user->nid),
                        'birth_certificate_hash' => IdentityKeys::key(IdentityKeys::BirthCertificate, $profile['birth_certificate_number'] ?? null),
                    ]);
                } catch (Throwable) {
                    continue;
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['nid_hash', 'birth_certificate_hash']);
        });
    }
};
