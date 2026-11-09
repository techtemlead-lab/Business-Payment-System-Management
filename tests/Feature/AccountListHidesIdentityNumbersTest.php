<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AccountListHidesIdentityNumbersTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_account_list_does_not_send_birth_certificate_numbers_or_birth_dates_to_the_browser(): void
    {
        User::factory()->dealer()->create(['profile' => [
            'place_code' => 'MNHC-1001',
            'father_name' => 'Test Father',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => 'TEST-12345678',
            'date_of_birth' => '1990-01-01',
        ]]);
        $this->withoutVite();

        $this->actingAs(User::factory()->staff('account_manager')->create())->onPortal('manage')->get('/accounts')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.accounts.data.0.profile.place_code', 'MNHC-1001')
                ->where('data.accounts.data.0.profile.father_name', 'Test Father')
                ->missing('data.accounts.data.0.profile.birth_certificate_number')
                ->missing('data.accounts.data.0.profile.date_of_birth'));
    }
}
