<?php

namespace Tests\Feature;

use App\Domain\Payouts\WithdrawalMethods;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner, 2026-11-27: bKash, Nagad and Rocket are on. The migration turns on exactly those switches when the
 * owner's saved setting has them off; Office, Bank and every other setting stay as they are, and a second run
 * changes nothing.
 */
class TurnOnMobileWithdrawalMethodsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_28_010000_turn_on_mobile_withdrawal_methods.php';

    public function test_stored_off_switches_are_turned_on_once_and_everything_else_is_kept(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->store($owner, ['Office' => true, 'Bank' => false, 'bKash' => false, 'Nagad' => false, 'Rocket' => false], 3);
        DB::table('business_settings')->insert(['key' => 'withdrawal_week', 'value' => json_encode(['enabled' => true, 'payout_day' => 4]), 'version' => 2, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
        $week = DB::table('business_settings')->where('key', 'withdrawal_week')->sole();

        $this->migration()->up();
        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        $this->assertSame(['Office' => true, 'Bank' => false, 'bKash' => true, 'Nagad' => true, 'Rocket' => true], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(4, (int) $row->version);
        $this->assertSame($owner->id, (int) $row->updated_by);
        $this->assertSame(['Office', 'bKash', 'Nagad', 'Rocket'], app(WithdrawalMethods::class)->enabled());
        $this->assertEquals($week, DB::table('business_settings')->where('key', 'withdrawal_week')->sole());
        $audit = DB::table('audit_events')->where('action', 'withdrawal.methods_changed')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame(WithdrawalMethods::SettingKey, $audit->subject);
        $metadata = json_decode((string) $audit->metadata, true);
        $this->assertFalse($metadata['before']['bKash']);
        $this->assertTrue($metadata['after']['Rocket']);
        $this->assertSame('migration', $metadata['source']);
    }

    public function test_only_the_three_mobile_ways_stored_as_off_change(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->store($owner, ['Office' => false, 'Bank' => true, 'bKash' => true, 'Nagad' => false], 7);

        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        // Office stays off and Bank stays on as the owner left them; Rocket was never stored, so it follows the code default.
        $this->assertSame(['Office' => false, 'Bank' => true, 'bKash' => true, 'Nagad' => true], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(8, (int) $row->version);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.methods_changed')->count());
    }

    public function test_nothing_is_written_when_no_setting_is_saved_or_all_three_are_on(): void
    {
        $this->migration()->up();
        $this->assertSame(0, DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->count());

        $owner = User::factory()->staff('super_admin')->create();
        $this->store($owner, ['Office' => true, 'Bank' => false, 'bKash' => true, 'Nagad' => true, 'Rocket' => true], 2);
        $this->migration()->up();

        $this->assertSame(2, (int) DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->value('version'));
        $this->assertSame(0, DB::table('audit_events')->where('action', 'withdrawal.methods_changed')->count());
    }

    /** @param array<string, bool> $enabled */
    private function store(User $owner, array $enabled, int $version): void
    {
        DB::table('business_settings')->insert(['key' => WithdrawalMethods::SettingKey, 'value' => json_encode(['enabled' => $enabled]), 'version' => $version, 'updated_by' => $owner->id, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/'.self::Migration);
    }
}
