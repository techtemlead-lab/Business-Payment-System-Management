<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php artisan madina:seed-place-codes: adds only what is missing, reports it, and can be run twice.
 */
class SeedPlaceCodesCommandTest extends TestCase
{
    use RefreshDatabase;

    private const Total = 1 + 8 + 64 + 499 + 4866;

    public function test_a_dry_run_reports_the_whole_list_and_saves_nothing(): void
    {
        $this->artisan('madina:seed-place-codes', ['--dry-run' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Total: '.self::Total.' added')
            ->assertSuccessful();

        $this->assertSame(0, PlaceCode::query()->count());
    }

    public function test_a_real_run_adds_every_place_with_its_default_code_and_a_second_run_adds_nothing(): void
    {
        $this->artisan('madina:seed-place-codes')->assertSuccessful();

        $this->assertSame(self::Total, PlaceCode::query()->whereNotNull('code')->count());
        $this->assertSame(1, PlaceCode::query()->where('code', 'MNHC100')->where('level', 'management')->count());

        $this->artisan('madina:seed-place-codes')
            ->expectsOutputToContain('Total: 0 added, 0 given a code, 0 names/parents filled, 0 base places set, 0 skipped.')
            ->assertSuccessful();
        $this->assertSame(self::Total, PlaceCode::query()->count());
    }

    public function test_existing_codes_are_never_changed_and_a_taken_default_code_is_skipped_and_reported(): void
    {
        $management = PlaceCode::query()->create(['level' => 'management', 'code' => 'MNHC100', 'name' => 'Management', 'sort_order' => 0, 'is_active' => true]);
        // Dhaka kept under the office's own code; a district sits on the default code of the Dhamrai upazila.
        $dhaka = PlaceCode::query()->create(['level' => 'division', 'code' => 'MNHC555', 'name' => 'Dhaka', 'parent_id' => $management->id, 'is_active' => true]);
        $other = PlaceCode::query()->create(['level' => 'district', 'code' => 'MNHC1001', 'name' => 'Savar Area', 'parent_id' => $dhaka->id, 'is_active' => true]);

        $this->artisan('madina:seed-place-codes', ['--dry-run' => true])
            ->expectsOutputToContain('Default codes already held by another place')
            ->assertSuccessful();
        $this->assertSame(3, PlaceCode::query()->count());

        $this->artisan('madina:seed-place-codes')->assertSuccessful();

        $this->assertSame('MNHC555', $dhaka->fresh()->code);
        $this->assertSame('MNHC1001', $other->fresh()->code);
        $dhamrai = PlaceCode::query()->where('level', 'upazila')->where('name', 'Dhamrai')->firstOrFail();
        $this->assertNull($dhamrai->code);
        $this->assertSame(1, PlaceCode::query()->where('code', 'MNHC1001')->count());
    }
}
