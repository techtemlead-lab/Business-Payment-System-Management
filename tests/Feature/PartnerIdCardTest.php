<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PartnerIdCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_can_download_partner_id_card_pdf(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-ID-1',
            'refer_code' => 'MNHC-ID-1',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/id-card/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Madina-partner-id-MNHC-ID-1.pdf');
    }

    public function test_staff_cannot_download_partner_id_card(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $this->actingAs($staff)->onPortal('manage')
            ->get('/id-card/pdf')
            ->assertNotFound();
    }

    public function test_places_path_redirects_to_place_codes(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);

        $this->actingAs($manager)->onPortal('manage')
            ->get('/places')
            ->assertRedirect('/place-codes');
    }
}
