<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use App\Services\BangladeshLocations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BangladeshLocationCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_import_has_complete_parent_links_nonempty_names_and_verified_record_counts(): void
    {
        $catalog = json_decode(file_get_contents(resource_path('bangladesh-locations.json')), true, 512, JSON_THROW_ON_ERROR);
        $counts = ['division' => 0, 'district' => 0, 'upazila' => 0, 'union_name' => 0];
        $nodes = $catalog['divisions'];
        foreach (BangladeshLocations::LEVELS as $level) {
            $counts[$level] = count($nodes);
            $this->assertSame([], array_values(array_filter($nodes, fn (array $node): bool => empty($node['name']) || empty($node['bn_name']) || empty($node['aliases']))));
            $children = [];
            foreach ($nodes as $node) {
                array_push($children, ...($node['children'] ?? []));
            }
            $nodes = $children;
        }
        $this->assertSame(['division' => 8, 'district' => 64, 'upazila' => 495, 'union_name' => 4540], $counts);
        $this->assertSame($counts, array_intersect_key($catalog['metadata']['counts'], $counts));
        $this->assertCount(110, $catalog['metropolitan_thanas']);
        $this->assertSame(110, $catalog['metadata']['counts']['metropolitan_thana']);
        $this->assertStringContainsString('Copyright (c) 2026 Iqbal Hasan', $catalog['metadata']['license']);
    }

    public function test_national_options_exist_without_any_dealers_and_follow_the_selected_parent(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 0)->has('locationOptions.division', 8)->where('locationOptions.district', [])->where('locationOptions.upazila', [])->where('locationOptions.union_name', [])->where('locationCatalog.counts.union_name', 4540));
        $this->get('/dealers?division=Dhaka&district=Dhaka&upazila=Savar')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 0)->has('locationOptions.district', 13)->has('locationOptions.upazila', 55)->where('locationOptions.upazila', fn (Collection $values): bool => $values->contains('Savar') && $values->contains('Gulshan (Thana)'))->has('locationOptions.union_name', 12)->where('locationLabels.union_name.Ashulia', 'Ashulia · আশুলিয়া')->where('legacyLocations.union_name', []));
        $this->get('/dealers?division=Chattogram&district=Coxsbazar')->assertInertia(fn (Assert $page) => $page->where('filters.district', "Cox's Bazar")->where('locationOptions.upazila', fn (Collection $values): bool => $values->contains('Eidgaon')));
    }

    public function test_old_spellings_case_and_bangla_names_match_without_rewriting_dealer_records(): void
    {
        $old = Dealer::factory()->create(['division' => 'Chittagong', 'district' => 'Comilla', 'upazila' => 'Debidwar']);
        $bangla = Dealer::factory()->create(['division' => 'Barishal', 'district' => 'বরিশাল']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?division=Chattogram&district=Cumilla')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.data.0.code', $old->code)->where('legacyLocations.division', [])->where('legacyLocations.district', []));
        $this->get('/dealers?division=barisal&district=বরিশাল')->assertInertia(fn (Assert $page) => $page->where('filters.division', 'Barishal')->where('filters.district', 'Barishal')->has('dealers.data', 1)->where('dealers.data.0.code', $bangla->code));
        $this->assertSame('Comilla', $old->fresh()->district);
        $this->assertSame('বরিশাল', $bangla->fresh()->district);
    }

    public function test_unmatched_saved_locations_remain_searchable_without_being_presented_as_catalog_entries(): void
    {
        $dealer = Dealer::factory()->create(['division' => 'Barishal', 'district' => 'barishal', 'upazila' => 'nayahat']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?division=Barishal&district=Barishal&upazila=nayahat')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.data.0.code', $dealer->code)->where('legacyLocations.upazila', ['nayahat'])->where('locationLabels.upazila.nayahat', 'nayahat (existing account location)')->where('locationOptions.upazila', fn (Collection $values): bool => $values->contains('Barishal Sadar') || $values->contains('Barisal Sadar')));
    }

    public function test_metropolitan_thanas_are_distinct_from_upazilas_and_match_existing_city_dealers(): void
    {
        $dealer = Dealer::factory()->create(['division' => 'Rajshahi', 'district' => 'Rajshahi', 'upazila' => 'Boalia']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?division=Rajshahi&district=Rajshahi&upazila=Boalia%20%28Thana%29')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.data.0.code', $dealer->code)->where('filters.upazila', 'Boalia (Thana)')->where('legacyLocations.upazila', [])->where('locationOptions.union_name', [])->where('locationOptions.upazila', fn (Collection $values): bool => $values->contains('Paba') && $values->contains('Paba (Thana)')));
        $this->get('/dealers?division=Barishal&district=Barishal')->assertInertia(fn (Assert $page) => $page->where('locationOptions.upazila', fn (Collection $values): bool => $values->contains('Kotwali (Thana)') && ! $values->contains('University (Thana)')));
    }
}
