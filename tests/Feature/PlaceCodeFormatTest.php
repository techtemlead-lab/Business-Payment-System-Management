<?php

namespace Tests\Feature;

use App\Domain\Accounts\DealerVerification;
use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Owner decision (2026-11-17): codes are written MNHC100 (no hyphen after the brand), and a
 * sub-dealer serial keeps one hyphen before its number: MNHC100001-000001. Codes typed the older
 * way (MNHC-100), bare digits, any case and spaces still find the same place or partner, so
 * printed ID cards keep working.
 */
class PlaceCodeFormatTest extends TestCase
{
    use RefreshDatabase;

    public function test_codes_are_written_without_a_hyphen_after_the_brand(): void
    {
        foreach (['100', 'MNHC100', 'MNHC-100', 'mnhc-100', ' mnhc 100 ', 'MNHC - 100'] as $typed) {
            $this->assertSame('MNHC100', PlaceCode::normalizeCode($typed), $typed);
        }
        $this->assertSame('MNHC100001', PlaceCode::normalizeCode('MNHC-100001'));
        $this->assertNull(PlaceCode::normalizeCode('MNHC-'));
        $this->assertNull(PlaceCode::normalizeCode('Dhaka'));
        $this->assertNull(PlaceCode::normalizeCode('—'));
        $this->assertSame(['MNHC1001', 'MNHC-1001', '1001'], PlaceCode::storedCodeForms('mnhc-1001'));
    }

    public function test_sub_dealer_serials_keep_only_the_hyphen_before_the_number(): void
    {
        $this->assertSame('MNHC100001-000001', PlaceCode::brandSerial('100001-000001'));
        $this->assertSame('MNHC100001-000001', PlaceCode::brandSerial('MNHC-100001-000001'));
        $this->assertSame('MNHC100001-000001', PlaceCode::brandSerial('mnhc100001-000001'));
        $this->assertSame('MNHC101', PlaceCode::brandSerial('MNHC-101'));
        $this->assertSame('MD-1001', PlaceCode::brandSerial('md-1001'));
        $this->assertSame(
            ['MNHC-100001-000001', 'MNHC100001-000001', '100001-000001'],
            PlaceCode::serialForms('mnhc-100001-000001'),
        );
    }

    public function test_a_place_saved_with_the_new_code_is_found_by_any_spelling(): void
    {
        $place = PlaceCode::factory()->create(['code' => '101', 'name' => 'Dhaka']);

        $this->assertSame('MNHC101', $place->fresh()->code);
        foreach (['MNHC101', 'MNHC-101', 'mnhc-101', '101', ' mnhc 101 '] as $typed) {
            $this->assertSame($place->id, PlaceCode::findByCode($typed)?->id, $typed);
        }
    }

    public function test_a_place_still_saved_with_the_older_code_is_found_by_the_new_one(): void
    {
        $id = DB::table('place_codes')->insertGetId([
            'level' => PlaceCode::LevelDivision, 'code' => 'MNHC-102', 'name' => 'Rangpur',
            'sort_order' => 0, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertSame($id, PlaceCode::findByCode('MNHC102')?->id);
        $this->assertSame($id, PlaceCode::findByCode('102')?->id);
    }

    public function test_the_next_sub_dealer_serial_counts_codes_saved_in_either_spelling(): void
    {
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC-100001-000007']);
        User::factory()->subDealer()->create(['dealer_code' => 'MNHC100001-000003']);
        $provisioning = app(PartnerAccountProvisioning::class);

        $this->assertSame('MNHC100001-000008', $provisioning->nextSubDealerCode('MNHC100001'));
        $this->assertSame('MNHC100001-000008', $provisioning->nextSubDealerCode('MNHC-100001'));
        $this->assertSame('MNHC100002-000001', $provisioning->nextSubDealerCode('100002'));
    }

    public function test_an_old_id_card_code_still_signs_the_partner_in(): void
    {
        Config::set('madina.login_otp.enabled', false);
        $subDealer = User::factory()->create([
            'role' => 'sub_dealer', 'status' => 'active', 'phone' => null,
            'dealer_code' => 'MNHC100001-000001', 'password' => 'SyntheticPassword123',
        ]);

        $this->onPortal('subdealer')
            ->post('/login', ['email' => 'MNHC-100001-000001', 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($subDealer);
    }

    public function test_an_old_id_card_code_still_verifies_the_dealer(): void
    {
        $place = PlaceCode::factory()->create(['code' => 'MNHC201', 'name' => 'Dhaka']);
        User::factory()->dealer()->create([
            'name' => 'Rahim Traders', 'status' => 'active', 'dealer_code' => 'MNHC201', 'refer_code' => 'MNHC201',
            'profile' => ['place_code' => 'MNHC-201'],
        ]);

        $result = app(DealerVerification::class)->lookup('MNHC-201');

        $this->assertNotNull($result);
        $this->assertSame('Rahim Traders', $result['name']);
        $this->assertTrue($result['valid']);
        $this->assertSame($place->code, $result['place_code']);
    }

    public function test_a_reference_typed_the_older_way_finds_the_sponsor(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'MNHC1001', 'refer_code' => 'MNHC1001']);
        $provisioning = app(PartnerAccountProvisioning::class);

        $this->assertSame($dealer->id, $provisioning->findSponsor('mnhc-1001')?->id);
        $this->assertTrue($provisioning->referCodeInUse('MNHC-1001'));
        $this->assertTrue($provisioning->isSameCode('MNHC-1001', 'mnhc1001'));
    }
}
