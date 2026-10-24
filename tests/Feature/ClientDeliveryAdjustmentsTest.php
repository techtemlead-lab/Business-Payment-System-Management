<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAccountProvisioning;
use App\Domain\Commissions\CommissionPolicy;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDeliveryAdjustmentsTest extends TestCase
{
    use RefreshDatabase;

    public function test_place_codes_normalize_to_mnhc_brand_prefix(): void
    {
        $this->assertSame('MNHC-100', PlaceCode::normalizeCode('100'));
        $this->assertSame('MNHC-100', PlaceCode::normalizeCode('mnhc-100'));
        $this->assertSame('MNHC-501-000001', PlaceCode::brandSerial('501-000001'));

        $management = PlaceCode::factory()->management('100')->create();
        $this->assertSame('MNHC-100', $management->code);
        $this->assertSame($management->id, PlaceCode::findByCode('100')?->id);
        $this->assertSame($management->id, PlaceCode::findByCode('MNHC-100')?->id);
    }

    public function test_place_preview_includes_management_share_and_area_pool_rates(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $management = PlaceCode::factory()->management('100')->create(['name' => 'Management']);
        $division = PlaceCode::factory()->division($management)->create(['code' => '102', 'name' => 'Rangpur']);
        $district = PlaceCode::factory()->district($division)->create(['code' => '301', 'name' => 'Rangpur']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => '401', 'name' => 'Sadar']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => '501', 'name' => 'City Corp']);
        $division->update(['base_place_id' => $district->id]);
        $district->update(['base_place_id' => $upazila->id]);
        $upazila->update(['base_place_id' => $union->id]);

        $this->actingAs($admin)->onPortal('sadmin')
            ->getJson('/commands/identity/place-preview?place_code=102&role=dealer')
            ->assertOk()
            ->assertJsonPath('data.label', 'MNHC-102-Division-Rangpur')
            ->assertJsonPath('data.area_pool_rate', '10.00')
            ->assertJsonPath('data.management_rate', '2.00')
            ->assertJsonPath('data.commission_places.0.level', 'management')
            ->assertJsonPath('data.commission_places.0.code', 'MNHC-100')
            ->assertJsonPath('data.commission_places.0.rate', '2.00')
            ->assertJsonPath('data.commission_places.1.code', 'MNHC-102')
            ->assertJsonPath('data.commission_places.4.code', 'MNHC-501');
    }

    public function test_mo_target_default_is_one_lakh(): void
    {
        $this->assertSame('100000.00', CommissionPolicy::defaults()['rank_royalty']['parameters']['mo_target']);
        $snapshot = app(CommissionPolicy::class)->snapshot(false);
        $this->assertSame('100000.00', $snapshot['rules']['rank_royalty']['parameters']['mo_target']);
    }

    public function test_sub_dealer_onboarding_stores_shop_name(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $management = PlaceCode::factory()->management()->create();
        $division = PlaceCode::factory()->division($management)->create(['code' => '101', 'name' => 'Dhaka']);
        $district = PlaceCode::factory()->district($division)->create(['code' => '201', 'name' => 'Dhaka']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => '301', 'name' => 'Savar']);
        $union = PlaceCode::factory()->union($upazila)->create(['code' => '401', 'name' => 'Savar Pourashava']);

        $created = app(PartnerAccountProvisioning::class)->create($admin, [
            'role' => 'sub_dealer',
            'identity_type' => 'nid',
            'nid' => '1234567890123',
            'date_of_birth' => '1990-01-01',
            'name' => 'Shop Owner',
            'name_bn' => 'দোকান',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => '401',
            'refer_code' => 'SHOP-REF-01',
            'phone' => '01711112233',
            'email' => 'shop.owner@example.test',
            'shop_name' => 'Madina Care Corner',
            'reason' => 'Verified shop onboarding',
        ]);

        $this->assertSame('Madina Care Corner', $created['user']->profile['shop_name'] ?? null);
        $this->assertSame('Madina Care Corner', $created['user']->profile['pharmacy'] ?? null);
        $this->assertStringStartsWith('MNHC-401-', (string) $created['user']->dealer_code);
    }
}
