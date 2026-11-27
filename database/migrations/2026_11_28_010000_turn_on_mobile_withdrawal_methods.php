<?php

use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-11-27: partners may take money out by bKash, Nagad and Rocket as well as cash at the office.
 *
 * The code default now has those ways on, but a server may still say otherwise, so after this runs they are on:
 * - A saved setting `withdrawal_methods`: exactly the bKash, Nagad and Rocket entries stored as off are turned
 *   on. Office, Bank and every other entry stay as they are.
 * - No saved setting: the ways in force now (config `madina.withdrawal_methods_default`, which the server's
 *   MADINA_WITHDRAWAL_METHODS may still set to "Office") are saved with bKash, Nagad and Rocket on; Office and
 *   Bank keep what they are now. The row is version 1 and names the Super Admin (or, without one, the first
 *   account) as the last editor, since the column needs a person; with no accounts at all nothing is saved.
 * Nothing happens when the three are already on, so running it again changes nothing. Every change is written
 * to the activity log like an owner's change ("withdrawal.methods_changed"), with no actor.
 *
 * down() does not switch the ways off again: the owner does that in Finance settings, with a reason.
 */
return new class extends Migration
{
    private const Key = 'withdrawal_methods';

    /** @var list<string> */
    private const TurnOn = ['bKash', 'Nagad', 'Rocket'];

    /** @var list<string> Every way, in the order the settings page shows them (WithdrawalMethods::all()). */
    private const All = ['Office', 'Bank', 'bKash', 'Nagad', 'Rocket'];

    public function up(): void
    {
        if (! Schema::hasTable('business_settings')) {
            return;
        }

        DB::transaction(function (): void {
            $row = DB::table('business_settings')->where('key', self::Key)->lockForUpdate()->first();
            if ($row === null) {
                $this->saveFromCurrentDefault();

                return;
            }
            $stored = json_decode((string) $row->value, true);
            if (! is_array($stored) || ! is_array($stored['enabled'] ?? null)) {
                return;
            }
            $before = $stored['enabled'];
            $after = $before;
            foreach (self::TurnOn as $method) {
                if (array_key_exists($method, $before) && ! filter_var($before[$method], FILTER_VALIDATE_BOOLEAN)) {
                    $after[$method] = true;
                }
            }
            if ($after === $before) {
                return;
            }
            $stored['enabled'] = $after;
            DB::table('business_settings')->where('key', self::Key)->update([
                'value' => json_encode($stored, JSON_THROW_ON_ERROR),
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);
            $this->log($before, $after);
        });
    }

    /** No saved setting: save the ways in force now, with bKash, Nagad and Rocket on. */
    private function saveFromCurrentDefault(): void
    {
        $current = (array) config('madina.withdrawal_methods_default', ['Office']);
        $before = [];
        foreach (self::All as $method) {
            $before[$method] = in_array($method, $current, true);
        }
        $after = [...$before, ...array_fill_keys(self::TurnOn, true)];
        if ($after === $before) {
            return;
        }
        $editor = DB::table('users')->where('role', 'super_admin')->orderBy('id')->value('id') ?? DB::table('users')->orderBy('id')->value('id');
        if ($editor === null) {
            return;
        }
        DB::table('business_settings')->insert([
            'key' => self::Key,
            'value' => json_encode(['enabled' => $after], JSON_THROW_ON_ERROR),
            'version' => 1,
            'updated_by' => $editor,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->log($before, $after);
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    private function log(array $before, array $after): void
    {
        if (Schema::hasTable('audit_events')) {
            Audit::record(null, 'withdrawal.methods_changed', self::Key, [
                'before' => $before,
                'after' => $after,
                'reason' => 'Owner decision 2026-11-27: bKash, Nagad and Rocket switched on when the update was installed.',
                'source' => 'migration',
            ]);
        }
    }

    public function down(): void
    {
        // Left on: switching a way off again is the owner's choice in Finance settings.
    }
};
