<?php

namespace Tests\Feature;

use App\Domain\Audience\PublicPartnerApplication;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminPartnershipsTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_partner_applications_appear_in_admin_partnerships_module(): void
    {
        $id = app(PublicPartnerApplication::class)->submit([
            'name' => 'Delivery Applicant',
            'email' => 'delivery.partner@example.test',
            'phone' => '01712223344',
            'role' => 'dealer',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
            'upazila' => 'Gulshan',
            'message' => 'Ready to open an authorized dealership.',
        ]);

        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->get('/partnerships')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'partnerships')
                ->has('data.application_counts', fn (Assert $counts) => $counts
                    ->where('total', 1)
                    ->where('pending', 1)
                    ->where('approved', 0)
                    ->where('rejected', 0)
                    ->etc())
                ->has('data.applications.data', 1)
                ->where('data.applications.data.0.id', $id)
                ->where('data.applications.data.0.name', 'Delivery Applicant')
                ->where('data.applications.data.0.email', 'delivery.partner@example.test')
                ->where('data.applications.data.0.role', 'dealer')
                ->where('data.applications.data.0.source', 'public')
                ->where('data.applications.data.0.status', 'pending'));
    }

    public function test_accounts_staff_can_review_partnership_application_from_portal_command(): void
    {
        $id = app(PublicPartnerApplication::class)->submit([
            'name' => 'Review Applicant',
            'email' => 'review.partner@example.test',
            'phone' => '01715556677',
            'role' => 'sub_dealer',
            'division' => 'Rangpur',
            'district' => 'Thakurgaon',
        ]);

        $reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($reviewer)->onPortal('sadmin')
            ->post('/commands/operations/staff/applications/'.$id.'/review', [
                'decision' => 'approved',
                'reason' => 'Territory and documents verified for delivery readiness.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('dealer_applications', [
            'id' => $id,
            'status' => 'approved',
        ]);
        $this->assertDatabaseHas('users', [
            'email' => 'review.partner@example.test',
            'role' => 'sub_dealer',
            'status' => 'active',
        ]);
    }

    public function test_partnership_page_uses_customizable_cta_link(): void
    {
        $this->assertSame('#partner-apply', SiteCustomization::defaults()['partnership_cta_link']);

        $this->onPortal('public')->get('/partnership')
            ->assertOk()
            ->assertSee('href="#partner-apply"', false)
            ->assertSee('Apply for Dealership', false)
            ->assertSee('autocomplete="name"', false)
            ->assertSee('autocomplete="email"', false)
            ->assertSee('autocomplete="tel"', false)
            ->assertSee('autocomplete="address-level1"', false)
            ->assertSee('autocomplete="address-level2"', false)
            ->assertSee('autocomplete="address-level3"', false);
    }
}
