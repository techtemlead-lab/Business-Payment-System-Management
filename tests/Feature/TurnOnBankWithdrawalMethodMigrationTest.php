<?php

namespace Tests\Feature;

use App\Domain\Payouts\WithdrawalMethods;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner, 2026-11-28: Bank is a way to take money out as well. The migration turns on only Bank, keeps every other
 * switch as the owner left it, and a second run changes nothing.
 */
class TurnOnBankWithdrawalMethodMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const Migration = '2026_11_28_020000_turn_on_bank_withdrawal_method.php';

    public function test_a_saved_setting_with_bank_off_gets_only_bank_turned_on_once(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->store($owner, ['Office' => true, 'Bank' => false, 'bKash' => true, 'Nagad' => false, 'Rocket' => true], 5);

        $this->migration()->up();
        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        $this->assertSame(['Office' => true, 'Bank' => true, 'bKash' => true, 'Nagad' => false, 'Rocket' => true], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(6, (int) $row->version);
        $audit = DB::table('audit_events')->where('action', 'withdrawal.methods_changed')->sole();
        $this->assertNull($audit->actor_id);
        $this->assertSame('migration', json_decode((string) $audit->metadata, true)['source']);
    }

    public function test_a_saved_setting_without_a_bank_entry_follows_the_code_default_and_gets_bank_on(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        config(['madina.withdrawal_methods_default' => ['Office', 'bKash']]);
        $this->store($owner, ['Office' => false, 'bKash' => true], 2);

        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        $this->assertSame(['Office' => false, 'bKash' => true, 'Bank' => true], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(3, (int) $row->version);
    }

    public function test_with_nothing_saved_the_ways_in_force_are_saved_with_bank_on(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        config(['madina.withdrawal_methods_default' => ['Office', 'bKash', 'Nagad', 'Rocket']]);

        $this->migration()->up();
        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        $this->assertSame(['Office' => true, 'Bank' => true, 'bKash' => true, 'Nagad' => true, 'Rocket' => true], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(1, (int) $row->version);
        $this->assertSame($owner->id, (int) $row->updated_by);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'withdrawal.methods_changed')->count());
    }

    public function test_nothing_changes_when_bank_is_already_on(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        $this->store($owner, ['Office' => false, 'Bank' => true, 'bKash' => false], 4);

        $this->migration()->up();

        $row = DB::table('business_settings')->where('key', WithdrawalMethods::SettingKey)->sole();
        $this->assertSame(['Office' => false, 'Bank' => true, 'bKash' => false], json_decode((string) $row->value, true)['enabled']);
        $this->assertSame(4, (int) $row->version);
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
