<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\DealershipRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealershipRequestTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['name' => 'Interested Partner', 'email' => 'PARTNER@EXAMPLE.TEST', 'mobile' => '+8801712345678', 'business_name' => 'Partner Trading', 'business_address' => '12 Test Road, Dhaka', 'level' => 'District', 'division' => 'Dhaka', 'district' => 'Dhaka', 'consent' => true];
    }

    public function test_public_request_reaches_company_without_creating_an_account(): void
    {
        $this->post('/request-dealership', [...$this->payload(), 'status' => 'reviewed', 'reviewed_by' => 99])->assertRedirect('/request-dealership')->assertSessionHas('success');
        $this->assertDatabaseHas('dealership_requests', ['email' => 'partner@example.test', 'status' => 'pending', 'reviewed_by' => null]);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('dealers', 0);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealership.request_received', 'actor_id' => null]);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('requestCount', 1)->where('requests.0.business_name', 'Partner Trading')->where('preview', null));
    }

    public function test_unrelated_public_forms_do_not_consume_the_request_rate_limit(): void
    {
        for ($index = 0; $index < 5; $index++) {
            $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertRedirect();
        }
        for ($index = 0; $index < 5; $index++) {
            $this->post('/request-dealership', $this->payload())->assertRedirect('/request-dealership');
        }
        $this->post('/request-dealership', $this->payload())->assertStatus(429);
        $this->assertDatabaseCount('dealership_requests', 5);
    }

    public function test_invalid_level_territory_mobile_and_consent_are_rejected(): void
    {
        $this->post('/request-dealership', [...$this->payload(), 'level' => 'Union', 'mobile' => 'bad', 'consent' => false])->assertSessionHasErrors(['upazila', 'union_name', 'mobile', 'consent']);
        $this->assertDatabaseCount('dealership_requests', 0);
        $this->post('/request-dealership', [...$this->payload(), 'level' => 'made-up', 'division' => 'made-up'])->assertSessionHasErrors(['level', 'division']);
    }

    public function test_guests_and_dealers_cannot_read_or_review_company_requests(): void
    {
        $application = DealershipRequest::factory()->create();
        $this->get('/dealership-requests')->assertRedirect('/login');
        $dealer = Dealer::factory()->create();
        $dealer->user->update(['role' => 'dealer']);
        $this->actingAs($dealer->user)->get('/dealership-requests')->assertForbidden();
        $this->patch('/dealership-requests/'.$application->id, ['status' => 'reviewed', 'reason' => 'Illegal review'])->assertForbidden();
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->missing('requests')->missing('requestCount'));
        $this->assertSame('pending', $application->fresh()->status);
    }

    public function test_company_can_search_review_and_record_an_audit_without_creating_a_dealer(): void
    {
        $application = DealershipRequest::factory()->create(['business_name' => 'MixedCase Trading']);
        DealershipRequest::factory()->create(['status' => 'reviewed']);
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get('/dealership-requests?q=mixedcase')->assertInertia(fn (Assert $page) => $page->has('requests.data', 1)->where('requests.data.0.id', $application->id));
        $this->patch('/dealership-requests/'.$application->id, ['status' => 'reviewed', 'reason' => 'Contact details verified'])->assertSessionHasErrors('password');
        $this->patch('/dealership-requests/'.$application->id, ['status' => 'reviewed', 'reason' => 'Contact details verified', 'password' => 'password'])->assertRedirect();
        $this->assertDatabaseHas('dealership_requests', ['id' => $application->id, 'status' => 'reviewed', 'reviewed_by' => $admin->id]);
        $this->assertDatabaseHas('audit_events', ['action' => 'dealership.request_reviewed', 'actor_id' => $admin->id]);
        $this->assertDatabaseCount('dealers', 0);
    }
}
