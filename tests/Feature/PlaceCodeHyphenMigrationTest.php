<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * The one-off migration that rewrites codes already saved as MNHC-100 to MNHC100, and back.
 */
class PlaceCodeHyphenMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MigrationFile = 'database/migrations/2026_11_17_164331_drop_hyphen_after_brand_prefix_in_place_codes.php';

    public function test_the_migration_drops_the_hyphen_everywhere_and_back(): void
    {
        $division = $this->legacyPlace('MNHC-101', 'Dhaka', PlaceCode::LevelDivision);
        $union = $this->legacyPlace('MNHC-100001', 'Amta', PlaceCode::LevelUnion);
        $dealer = User::factory()->dealer()->create([
            'dealer_code' => 'MNHC-101', 'refer_code' => 'MNHC-101',
            'profile' => ['place_code' => 'MNHC-101', 'area_code' => 'MNHC-101', 'management_refer_code' => 'MNHC-100', 'note' => 'MNHC-101 stays as typed', 'empty' => (object) []],
        ]);
        $subDealer = User::factory()->subDealer()->create([
            'dealer_code' => 'MNHC-100001-000001', 'refer_code' => 'MNHC-100001-000001',
            'profile' => ['place_code' => 'MNHC-100001', 'union_place_code' => 'MNHC-100001', 'area_code' => 'MNHC-100001-000001'],
        ]);
        $other = User::factory()->dealer()->create(['dealer_code' => 'MD-1001', 'refer_code' => 'MD-1001']);
        DB::table('partner_former_codes')->insert(['user_id' => $dealer->id, 'code' => 'MNHC-102', 'created_at' => now(), 'updated_at' => now()]);
        $seatKey = hash('sha256', PlaceCode::LevelDivision.'|code:MNHC-101');
        DB::table('area_dealer_assignments')->insert([
            'user_id' => $dealer->id, 'level' => PlaceCode::LevelDivision, 'zone_name' => 'Dhaka', 'monthly_quota' => '0.00',
            'current_month_purchase' => '0.00', 'is_active' => true, 'territory_key' => $seatKey, 'place_code_id' => $division,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $migration = require base_path(self::MigrationFile);
        $migration->up();

        $this->assertSame('MNHC101', DB::table('place_codes')->where('id', $division)->value('code'));
        $this->assertSame('MNHC100001', DB::table('place_codes')->where('id', $union)->value('code'));
        $dealer->refresh();
        $subDealer->refresh();
        $this->assertSame(['MNHC101', 'MNHC101'], [$dealer->dealer_code, $dealer->refer_code]);
        $this->assertSame(['MNHC100001-000001', 'MNHC100001-000001'], [$subDealer->dealer_code, $subDealer->refer_code]);
        $this->assertSame('MNHC101', $dealer->profile['place_code']);
        $this->assertSame('MNHC101', $dealer->profile['area_code']);
        $this->assertSame('MNHC100', $dealer->profile['management_refer_code']);
        $this->assertSame('MNHC-101 stays as typed', $dealer->profile['note']);
        $this->assertStringContainsString('"empty":{}', (string) DB::table('users')->where('id', $dealer->id)->value('profile'));
        $this->assertSame('MNHC100001', $subDealer->profile['union_place_code']);
        $this->assertSame('MNHC100001-000001', $subDealer->profile['area_code']);
        $this->assertSame('MD-1001', $other->fresh()->dealer_code);
        $this->assertSame('MNHC102', DB::table('partner_former_codes')->where('user_id', $dealer->id)->value('code'));
        $this->assertSame(
            hash('sha256', PlaceCode::LevelDivision.'|code:MNHC101'),
            DB::table('area_dealer_assignments')->where('user_id', $dealer->id)->value('territory_key'),
        );

        $migration->down();

        $this->assertSame('MNHC-101', DB::table('place_codes')->where('id', $division)->value('code'));
        $this->assertSame('MNHC-100001-000001', $subDealer->fresh()->dealer_code);
        $this->assertSame('MNHC-100001', $subDealer->fresh()->profile['union_place_code']);
        $this->assertSame('MNHC-102', DB::table('partner_former_codes')->where('user_id', $dealer->id)->value('code'));
        $this->assertSame($seatKey, DB::table('area_dealer_assignments')->where('user_id', $dealer->id)->value('territory_key'));
    }

    public function test_the_migration_leaves_a_row_alone_when_its_new_spelling_is_taken(): void
    {
        $old = $this->legacyPlace('MNHC-105', 'Khulna', PlaceCode::LevelDivision);
        $new = $this->legacyPlace('MNHC105', 'Khulna (new)', PlaceCode::LevelDivision);
        $first = User::factory()->dealer()->create(['dealer_code' => 'MNHC-105', 'refer_code' => 'MNHC-105']);
        $second = User::factory()->dealer()->create(['dealer_code' => 'MNHC105', 'refer_code' => 'MNHC105']);
        Log::spy();

        (require base_path(self::MigrationFile))->up();

        $this->assertSame('MNHC-105', DB::table('place_codes')->where('id', $old)->value('code'));
        $this->assertSame('MNHC105', DB::table('place_codes')->where('id', $new)->value('code'));
        $this->assertSame('MNHC-105', $first->fresh()->dealer_code);
        $this->assertSame('MNHC105', $second->fresh()->dealer_code);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => count($context['rows']) === 3);
        // The row left in the older spelling is still found by the new one.
        $this->assertContains(PlaceCode::findByCode('MNHC105')?->id, [$old, $new]);
    }

    private function legacyPlace(string $code, string $name, string $level): int
    {
        return DB::table('place_codes')->insertGetId([
            'level' => $level, 'code' => $code, 'name' => $name,
            'sort_order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
