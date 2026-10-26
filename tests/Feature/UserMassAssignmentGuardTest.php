<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserMassAssignmentGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_privilege_columns_are_not_mass_assignable(): void
    {
        $user = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'sponsor_id' => null,
            'dealer_code' => 'D-GUARD-1',
            'nid' => '1234567890',
        ]);

        $user->fill([
            'role' => 'super_admin',
            'status' => 'suspended',
            'sponsor_id' => 999,
            'dealer_code' => 'HACKED',
            'nid' => '0000000000',
            'name' => 'Safe Name',
        ])->save();

        $user->refresh();

        $this->assertSame('dealer', $user->role);
        $this->assertSame('active', $user->status);
        $this->assertNull($user->sponsor_id);
        $this->assertSame('D-GUARD-1', $user->dealer_code);
        $this->assertSame('1234567890', $user->nid);
        $this->assertSame('Safe Name', $user->name);
    }
}
