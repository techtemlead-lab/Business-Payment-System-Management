<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AddPartnerPlacePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_check_button_names_the_place_level_in_the_page_language(): void
    {
        $office = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $district = PlaceCode::factory()->district(PlaceCode::factory()->division()->create())->create();
        $union = PlaceCode::factory()->union(PlaceCode::factory()->upazila($district)->create())->create();

        $response = $this->actingAs($office)->onPortal('sadmin')
            ->getJson('/commands/identity/place-preview?role=dealer&place_code='.urlencode((string) $union->code));

        $response->assertOk();
        $this->assertSame(
            $union->code.'-'.__('portal.pc_lvl_union', [], 'bn').'-'.$union->name,
            $response->json('data.label'),
        );
    }
}
