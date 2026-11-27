<?php

use App\Support\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Owner, 2026-11-27: partners may take money out by bKash, Nagad and Rocket as well as cash at the office.
 *
 * The code default now has those ways on, but an install where the owner once saved the switches keeps a stored
 * "off" for them (the 2026-11-21 round saved Office only). This turns exactly those stored switches on:
 * only when the saved setting `withdrawal_methods` exists, and only the bKash, Nagad and Rocket entries that are
 * stored as off. Office, Bank and every other setting are left as they are; nothing happens when no setting is
 * saved (the new code default applies) or when the three are already on, so running it again changes nothing.
 * The change is written to the activity log like an owner's change ("withdrawal.methods_changed").
 *
 * down() does not switch the ways off again: the owner does that in Finance settings, with a reason.
 */
return new class extends Migration
{
    private const Key = 'withdrawal_methods';

    /** @var list<string> */
    private const TurnOn = ['bKash', 'Nagad', 'Rocket'];

    public function up(): void
    {
        if (! Schema::hasTable('business_settings')) {
            return;
        }

        DB::transaction(function (): void {
            $row = DB::table('business_settings')->where('key', self::Key)->lockForUpdate()->first();
            if ($row === null) {
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
            if (Schema::hasTable('audit_events')) {
                Audit::record(null, 'withdrawal.methods_changed', self::Key, [
                    'before' => $before,
                    'after' => $after,
                    'reason' => 'Owner decision 2026-11-27: bKash, Nagad and Rocket switched on when the update was installed.',
                    'source' => 'migration',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Left on: switching a way off again is the owner's choice in Finance settings.
    }
};
