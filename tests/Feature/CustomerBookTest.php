<?php

namespace Tests\Feature;

use App\Models\PartnerCustomer;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CustomerBookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_partner_adds_edits_and_finishes_a_customer(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/customers', [
            'name' => 'Karim Mia', 'phone' => '+8801711223344', 'notes' => 'Wants 2 boxes', 'follow_up_on' => '2026-12-01',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $customer = PartnerCustomer::query()->sole();
        $this->assertSame((int) $dealer->id, (int) $customer->partner_id);
        $this->assertSame('01711223344', $customer->phone);

        $this->actingAs($dealer)->onPortal('dealer')->patch('/commands/customers/'.$customer->id, ['name' => 'Karim Uddin', 'phone' => '', 'notes' => null, 'follow_up_on' => '2026-12-05'])->assertRedirect();
        $this->assertSame('Karim Uddin', $customer->fresh()->name);
        $this->assertNull($customer->fresh()->phone);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/customers/'.$customer->id.'/follow-up-done')->assertRedirect();
        $this->assertNull($customer->fresh()->follow_up_on);

        $this->actingAs($dealer)->onPortal('dealer')->delete('/commands/customers/'.$customer->id)->assertRedirect();
        $this->assertSame(0, PartnerCustomer::query()->count());
    }

    public function test_a_bad_mobile_number_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/customers', ['name' => 'K', 'phone' => '12345'])
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, PartnerCustomer::query()->count());
    }

    public function test_the_book_lists_only_my_own_customers_with_due_first(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $dealer = User::factory()->dealer()->create();
        $other = User::factory()->dealer()->create();
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Later', 'follow_up_on' => '2026-12-20']);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Due today', 'follow_up_on' => '2026-11-10']);
        PartnerCustomer::query()->create(['partner_id' => $other->id, 'name' => 'Not mine', 'follow_up_on' => '2026-11-10']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/customers')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.customer_book.customers.data', 2)
                ->where('data.customer_book.customers.data.0.name', 'Due today')
                ->where('data.customer_book.due_count', 1));
    }

    public function test_the_owner_and_staff_cannot_reach_a_partners_customers(): void
    {
        $dealer = User::factory()->dealer()->create();
        $customer = PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Secret']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        foreach ([[$owner, 'sadmin'], [$staff, 'manage']] as [$viewer, $portal]) {
            $this->actingAs($viewer)->onPortal($portal)->get('/customers')->assertNotFound();
            $this->assertContains($this->actingAs($viewer)->onPortal($portal)->post('/commands/customers', ['name' => 'X'])->status(), [403, 404]);
            $this->assertContains($this->actingAs($viewer)->onPortal($portal)->patch('/commands/customers/'.$customer->id, ['name' => 'Y'])->status(), [403, 404]);
            $this->assertContains($this->actingAs($viewer)->onPortal($portal)->delete('/commands/customers/'.$customer->id)->status(), [403, 404]);
        }
        $this->assertSame('Secret', $customer->fresh()->name);
        $this->assertSame(1, PartnerCustomer::query()->count());
    }

    public function test_another_partner_cannot_touch_my_customers(): void
    {
        $dealer = User::factory()->dealer()->create();
        $customer = PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Mine']);
        $other = User::factory()->dealer()->create();

        $this->actingAs($other)->onPortal('dealer')->patch('/commands/customers/'.$customer->id, ['name' => 'Stolen'])->assertNotFound();
        $this->actingAs($other)->onPortal('dealer')->delete('/commands/customers/'.$customer->id)->assertNotFound();
        $this->assertSame('Mine', $customer->fresh()->name);
    }

    public function test_the_book_has_no_stock_or_product_link(): void
    {
        $columns = Schema::getColumnListing('partner_customers');

        $this->assertEmpty(array_filter($columns, fn (string $column): bool => str_contains($column, 'product') || str_contains($column, 'stock') || str_contains($column, 'quantity')));
    }

    public function test_the_morning_reminder_posts_counts_once_a_day_without_names(): void
    {
        Carbon::setTestNow('2026-11-10 06:00:00');
        $dealer = User::factory()->dealer()->create(['locale' => 'en']);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Rahima Begum', 'phone' => '01711223344', 'follow_up_on' => '2026-11-10']);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Old One', 'follow_up_on' => '2026-11-01']);
        PartnerCustomer::query()->create(['partner_id' => $dealer->id, 'name' => 'Later', 'follow_up_on' => '2026-12-01']);

        $this->artisan('partners:customer-follow-up-reminders')->assertSuccessful();
        $this->artisan('partners:customer-follow-up-reminders')->assertSuccessful();

        $notices = DB::table('account_notices')->where('user_id', $dealer->id)->get();
        $this->assertCount(1, $notices);
        $this->assertStringContainsString('1 due today', (string) $notices[0]->body);
        $this->assertStringContainsString('1 late', (string) $notices[0]->body);
        $this->assertStringNotContainsString('Rahima', (string) $notices[0]->body);
        $this->assertStringNotContainsString('01711223344', (string) $notices[0]->body);
    }
}
