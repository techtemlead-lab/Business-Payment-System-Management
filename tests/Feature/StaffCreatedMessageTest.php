<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffCreatedMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_staff_member_is_not_given_their_name_as_a_code(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin', 'locale' => 'en']);

        $this->actingAs($admin)->post('/commands/accounts', [
            'role' => 'employee',
            'name' => 'Rahima Staff',
            'email' => 'rahima.staff@example.test',
            'phone' => '01799998877',
            'status' => 'active',
            'permissions' => ['orders'],
            'reason' => 'Adding a new warehouse staff member for this test.',
        ])->assertRedirect('/employees')
            ->assertSessionHas('success', fn (string $message): bool => ! str_contains($message, 'Rahima Staff') && ! str_contains($message, 'Code:'));
    }
}
