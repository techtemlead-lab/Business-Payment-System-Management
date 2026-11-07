<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class StaffTourCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function officeRoles(): array
    {
        return [
            'finance' => ['finance'],
            'account manager' => ['account_manager'],
            'inventory' => ['inventory'],
            'support' => ['support'],
        ];
    }

    #[DataProvider('officeRoles')]
    public function test_every_office_role_can_close_and_replay_its_own_welcome_tour(string $role): void
    {
        $staff = User::factory()->staff($role)->create(['status' => 'active']);

        $this->actingAs($staff)
            ->onPortal('manage')
            ->postJson('/commands/tours/overview/complete')
            ->assertOk()
            ->assertJson(['ok' => true]);

        $this->assertDatabaseHas('tour_completions', [
            'user_id' => $staff->id,
            'page_key' => 'overview',
        ]);

        $this->actingAs($staff)
            ->onPortal('manage')
            ->post('/commands/tours/restart', ['page' => 'overview'])
            ->assertRedirect();
    }

    public function test_closing_a_tour_only_records_the_signed_in_persons_progress(): void
    {
        $support = User::factory()->staff('support')->create(['status' => 'active']);
        $other = User::factory()->staff('finance')->create(['status' => 'active']);

        $this->actingAs($support)
            ->onPortal('manage')
            ->postJson('/commands/tours/overview/complete')
            ->assertOk();

        $this->assertDatabaseMissing('tour_completions', [
            'user_id' => $other->id,
            'page_key' => 'overview',
        ]);
    }
}
