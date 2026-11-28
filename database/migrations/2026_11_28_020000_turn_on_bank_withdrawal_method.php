<?php

use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-11-28: partners may take money out by bank as well (saved bank account, sent by the office).
 *
 * Same safe way as the 2026-11-28 mobile-ways migration:
 * - A saved setting `withdrawal_methods` where Bank is off (stored as off, or not stored and off by the code
 *   default): only Bank is turned on. Office, bKash, Nagad, Rocket and every other entry stay as the owner left them.
 * - No saved setting: the ways in force now (config `madina.withdrawal_methods_default`) are saved with Bank on.
 *   The row is version 1 and names the Super Admin (or, without one, the first account) as the last editor;
 *   with no accounts at all nothing is saved.
 * Nothing happens when Bank is already on, so running it again changes nothing. Every change is written to the
 * activity log like an owner's change ("withdrawal.methods_changed"), with no actor.
 *
 * down() does not switch Bank off again: the owner does that in Finance settings, with a reason.
 */
return new class extends Migration
{
    private const Key = 'withdrawal_methods';

    private const Method = 'Bank';

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
            $effective = array_key_exists(self::Method, $before)
                ? filter_var($before[self::Method], FILTER_VALIDATE_BOOLEAN)
                : in_array(self::Method, (array) config('madina.withdrawal_methods_default', ['Office']), true);
            if ($effective) {
                return;
            }
            $after = [...$before, self::Method => true];
            $stored['enabled'] = $after;
            DB::table('business_settings')->where('key', self::Key)->update([
                'value' => json_encode($stored, JSON_THROW_ON_ERROR),
                'version' => (int) $row->version + 1,
                'updated_at' => now(),
            ]);
            $this->log($before, $after);
        });
    }

    /** No saved setting: save the ways in force now, with Bank on. */
    private function saveFromCurrentDefault(): void
    {
        $current = (array) config('madina.withdrawal_methods_default', ['Office']);
        $before = [];
        foreach (self::All as $method) {
            $before[$method] = in_array($method, $current, true);
        }
        $after = [...$before, self::Method => true];
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
                'reason' => 'Owner decision 2026-11-28: Bank switched on when the update was installed.',
                'source' => 'migration',
            ]);
        }
    }

    public function down(): void
    {
        // Left on: switching a way off again is the owner's choice in Finance settings.
    }
};
