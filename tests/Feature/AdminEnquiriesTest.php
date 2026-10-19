<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AdminEnquiriesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_contact_enquiries_appear_in_admin_enquiries_module(): void
    {
        $payload = [
            'name' => 'Admin Module Visitor',
            'phone' => '01711112222',
            'topic' => 'Partnership enquiry',
            'message' => 'I would like to join as an authorized dealer in Dhaka.',
        ];

        $this->onPortal('public')
            ->from('/contact')
            ->post('/enquiries', $payload)
            ->assertRedirect('/contact')
            ->assertSessionHas('success');

        $this->assertDatabaseHas('enquiries', [
            'name' => 'Admin Module Visitor',
            'phone' => '01711112222',
            'topic' => 'Partnership enquiry',
            'status' => 'new',
        ]);

        $admin = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($admin)->onPortal('sadmin')->get('/enquiries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'enquiries')
                ->has('data.enquiry_counts', fn (Assert $counts) => $counts
                    ->where('total', 1)
                    ->where('new', 1)
                    ->where('contacted', 0)
                    ->where('closed', 0)
                    ->etc())
                ->has('data.enquiries.data', 1)
                ->where('data.enquiries.data.0.name', 'Admin Module Visitor')
                ->where('data.enquiries.data.0.topic', 'Partnership enquiry')
                ->where('data.enquiries.data.0.message', 'I would like to join as an authorized dealer in Dhaka.')
                ->where('data.enquiries.data.0.status', 'new'));

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')->get('/enquiries')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->where('section', 'enquiries')
                ->where('data.enquiries.data.0.name', 'Admin Module Visitor'));
    }

    public function test_staff_can_update_enquiry_follow_up_status(): void
    {
        $id = DB::table('enquiries')->insertGetId([
            'name' => 'Follow-up Visitor',
            'phone' => '01700001111',
            'topic' => 'Support',
            'message' => 'Need help with dealer directory access details.',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/enquiries/'.$id, ['status' => 'contacted'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('enquiries', ['id' => $id, 'status' => 'contacted']);
    }
}
