<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VolumeHistoryCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_volume_history_refuses_to_seed_the_testing_database(): void
    {
        $this->artisan('madina:volume-history')
            ->expectsOutputToContain('madina')
            ->assertFailed();
    }
}
