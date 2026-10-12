<?php

namespace Tests\Feature;

use App\Models\Dealer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DealerLocationFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_location_filters_combine_with_search_status_and_account_type(): void
    {
        $matched = Dealer::factory()->areaDealer()->create(['code' => 'LOC-MATCH', 'business_name' => 'Health partner', 'level' => 'Union', 'district' => 'Dhaka', 'upazila' => 'Savar', 'union_name' => 'Ashulia']);
        Dealer::factory()->create(['district' => 'Dhaka', 'upazila' => 'Savar', 'union_name' => 'Ashulia']);
        Dealer::factory()->areaDealer()->pending()->create(['district' => 'Dhaka', 'upazila' => 'Savar', 'union_name' => 'Ashulia']);
        Dealer::factory()->areaDealer()->create(['business_name' => 'Health partner', 'district' => 'Dhaka', 'upazila' => 'Savar', 'union_name' => 'Birulia']);
        Dealer::factory()->areaDealer()->create(['business_name' => 'Health partner', 'district' => 'Dhaka', 'upazila' => 'Dhamrai', 'union_name' => 'Other']);
        Dealer::factory()->create(['division' => 'Chattogram', 'district' => 'Chattogram', 'upazila' => 'Patiya', 'union_name' => 'Other']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?division=Dhaka&district=Dhaka&upazila=Savar&union_name=Ashulia&dealer_type=area_dealer&status=active&level=Union&q=Health')->assertInertia(fn (Assert $page) => $page->component('Dealers')->has('dealers.data', 1)->where('dealers.data.0.code', $matched->code)->where('dealers.data.0.upazila', 'Savar')->where('dealers.data.0.union_name', 'Ashulia')->where('filters.division', 'Dhaka')->where('locationOptions.district', fn (Collection $values): bool => $values->contains('Dhaka') && ! $values->contains('Chattogram'))->has('locationOptions.upazila', 55)->where('locationOptions.union_name', fn (Collection $values): bool => $values->contains('Ashulia') && $values->contains('Birulia')));
    }

    public function test_a_location_can_be_selected_on_its_own_and_empty_territories_are_not_options(): void
    {
        $dealer = Dealer::factory()->create(['district' => 'Gazipur', 'upazila' => 'Kaliganj', 'union_name' => 'Nagari']);
        Dealer::factory()->create(['division' => 'Sylhet', 'district' => null, 'upazila' => '', 'union_name' => null]);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?district=Gazipur')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.data.0.code', $dealer->code)->has('locationOptions.division', 8)->has('locationOptions.district', 64)->has('locationOptions.upazila', 13)->where('locationOptions.union_name', []));
        $this->get('/dealers?division=Barishal')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 0)->has('locationOptions.district', 6));
    }

    public function test_pagination_preserves_location_filters_and_invalid_filters_are_rejected(): void
    {
        Dealer::factory()->count(13)->create(['district' => 'Gazipur']);
        Dealer::factory()->create(['district' => 'Dhaka']);
        $this->actingAs(User::factory()->create(['role' => 'admin']))->get('/dealers?division=Dhaka&district=Gazipur&page=2')->assertInertia(fn (Assert $page) => $page->has('dealers.data', 1)->where('dealers.total', 13)->where('filters.district', 'Gazipur')->where('dealers.links.0.url', fn (string $url): bool => str_contains($url, 'division=Dhaka') && str_contains($url, 'district=Gazipur')));
        $this->get('/dealers?division[]=Dhaka')->assertSessionHasErrors('division');
        $this->get('/dealers?union_name='.str_repeat('a', 101))->assertSessionHasErrors('union_name');
    }
}
