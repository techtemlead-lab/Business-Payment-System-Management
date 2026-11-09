<?php

namespace Tests\Feature;

use App\Models\SiteFeedback;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WebsiteToolsListsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    private function feedback(string $status, string $name): SiteFeedback
    {
        return SiteFeedback::factory()->create(['status' => $status, 'name' => $name, 'source' => 'public', 'rating' => 5, 'product_id' => null]);
    }

    private function enquiry(string $name, string $status, string $topic = 'general'): int
    {
        return DB::table('enquiries')->insertGetId([
            'name' => $name,
            'phone' => '01700000000',
            'topic' => $topic,
            'message' => 'A question from '.$name,
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_feedback_lists_waiting_rows_first_and_counts_each_status(): void
    {
        $this->feedback('approved', 'Approved Person');
        $this->feedback('rejected', 'Rejected Person');
        $this->feedback('pending', 'Waiting Person');

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/feedback')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.feedback_table.rows.data.0.name', 'Waiting Person')
                ->where('data.feedback_table.rows.data.1.name', 'Approved Person')
                ->where('data.feedback_table.rows.data.2.name', 'Rejected Person')
                ->where('data.feedback_table.totals.count', 3)
                ->where('data.feedback_table.filters.0.key', 'status')
                ->where('data.feedback_table.filters.0.options.0.count', 1)
                ->where('data.feedback_counts.pending', 1)
                ->where('data.feedback_counts.total', 3));
    }

    public function test_feedback_is_no_longer_cut_off_at_a_hundred_rows(): void
    {
        SiteFeedback::factory()->count(101)->create(['status' => 'approved', 'source' => 'public', 'product_id' => null]);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/feedback?page=5')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.feedback_table.totals.count', 101)
                ->has('data.feedback_table.rows.data', 1));
    }

    public function test_feedback_can_be_filtered_and_searched_on_the_server(): void
    {
        $this->feedback('approved', 'Rahim Uddin');
        $this->feedback('pending', 'Karim Mia');

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/feedback?status=approved')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.feedback_table.rows.data', 1)
                ->where('data.feedback_table.rows.data.0.name', 'Rahim Uddin')
                ->where('data.feedback_table.state.filters.status', 'approved'));

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/feedback?q=Karim')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.feedback_table.rows.data', 1)
                ->where('data.feedback_table.rows.data.0.name', 'Karim Mia'));
    }

    public function test_enquiry_filter_addresses_keep_working_on_the_new_list(): void
    {
        $this->enquiry('New Asker', 'new');
        $this->enquiry('Closed Asker', 'closed');

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/enquiries?status=closed')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.enquiries_table.rows.data', 1)
                ->where('data.enquiries_table.rows.data.0.name', 'Closed Asker')
                ->where('data.enquiries_table.state.filters.status', 'closed')
                ->where('data.enquiry_counts.new', 1)
                ->where('data.enquiry_counts.closed', 1)
                ->where('data.enquiry_counts.total', 2));

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/enquiries?q=New%20Asker')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.enquiries_table.rows.data', 1)
                ->where('data.enquiries_table.state.q', 'New Asker'));
    }

    public function test_page_text_list_filters_by_published_state(): void
    {
        DB::table('content_pages')->insert([
            ['slug' => 'contact', 'title' => 'Shown page', 'body' => 'Body', 'published' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'partnership', 'title' => 'Draft page', 'body' => 'Body', 'published' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->actingAs($this->owner())->onPortal('sadmin')->get('/content?published=0')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.pages_table.rows.data', 1)
                ->where('data.pages_table.rows.data.0.title', 'Draft page')
                ->where('data.page_slugs', fn ($slugs): bool => collect($slugs)->contains('contact')));
    }
}
