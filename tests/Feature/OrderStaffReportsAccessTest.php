<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderStaffReportsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_manager_can_open_order_reports_and_export_csv(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);

        $this->actingAs($manager)->onPortal('manage')
            ->get('/reports')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('portals/manage/Dashboard')
                ->where('section', 'reports'));

        $this->actingAs($manager)->onPortal('manage')
            ->get('/exports/orders')
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=utf-8');
    }

    public function test_content_only_staff_cannot_open_order_reports(): void
    {
        $staff = User::factory()->create(['role' => 'editor', 'status' => 'active']);

        $this->actingAs($staff)->onPortal('manage')->get('/reports')->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->get('/exports/orders')->assertForbidden();
    }
}
