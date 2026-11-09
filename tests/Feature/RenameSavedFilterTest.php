<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Renaming a saved filter keeps what it filters, and only ever touches the person's own filter. */
class RenameSavedFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_or_blank_name_is_refused_in_the_persons_language(): void
    {
        foreach (['bn' => 'নাম দিতে হবে', 'en' => 'name'] as $locale => $expected) {
            $person = User::factory()->staff()->create(['locale' => $locale]);
            $viewId = $this->saved($person, 'Waiting');

            foreach (['', '    '] as $blank) {
                $response = $this->actingAs($person)->onPortal('sadmin')
                    ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => $blank])
                    ->assertUnprocessable();
                $this->assertStringContainsString($expected, (string) $response->json('errors.name.0'));
            }
            $this->assertDatabaseHas('saved_list_filters', ['id' => $viewId, 'name' => 'Waiting']);
        }
    }

    public function test_a_name_that_is_too_long_is_refused(): void
    {
        $person = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Waiting');

        $this->actingAs($person)->onPortal('sadmin')
            ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => str_repeat('a', 61)])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_renaming_keeps_the_filters_and_returns_the_new_list(): void
    {
        $person = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Waiting', ['status' => 'submitted', 'period' => 'today']);

        $this->actingAs($person)->onPortal('sadmin')
            ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => '  Waiting today  '])
            ->assertOk()
            ->assertJsonPath('data.views.0.id', $viewId)
            ->assertJsonPath('data.views.0.name', 'Waiting today')
            ->assertJsonPath('data.views.0.filters.status', 'submitted')
            ->assertJsonPath('data.views.0.filters.period', 'today')
            ->assertJsonCount(1, 'data.views');
    }

    public function test_renaming_to_the_same_name_is_fine(): void
    {
        $person = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Waiting');

        $this->actingAs($person)->onPortal('sadmin')
            ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => 'Waiting'])
            ->assertOk()
            ->assertJsonPath('data.views.0.name', 'Waiting');
    }

    public function test_renaming_onto_another_saved_filter_is_refused_and_keeps_both(): void
    {
        foreach (['bn' => 'এই নামে', 'en' => 'already'] as $locale => $expected) {
            $person = User::factory()->staff()->create(['locale' => $locale]);
            $first = $this->saved($person, 'First', ['status' => 'held']);
            $second = $this->saved($person, 'Second', ['status' => 'submitted']);

            $response = $this->actingAs($person)->onPortal('sadmin')
                ->patchJson('/commands/list-views/orders/'.$first, ['name' => 'Second'])
                ->assertUnprocessable();

            $this->assertStringContainsString($expected, (string) $response->json('errors.name.0'));
            $this->assertDatabaseHas('saved_list_filters', ['id' => $first, 'name' => 'First']);
            $this->assertDatabaseHas('saved_list_filters', ['id' => $second, 'name' => 'Second']);
        }
    }

    public function test_the_same_name_on_another_list_or_for_another_person_is_allowed(): void
    {
        $person = User::factory()->staff()->create();
        $other = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Waiting');
        $this->saved($other, 'Mine too');
        $this->actingAs($person)->onPortal('sadmin')
            ->postJson('/commands/list-views/deliveries', ['name' => 'Mine too', 'filters' => ['status' => 'held']])->assertOk();

        $this->actingAs($person)->onPortal('sadmin')
            ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => 'Mine too'])
            ->assertOk()
            ->assertJsonPath('data.views.0.name', 'Mine too');
    }

    public function test_saving_under_an_existing_name_still_replaces_it(): void
    {
        $person = User::factory()->staff()->create();
        $first = $this->saved($person, 'Waiting', ['status' => 'held']);
        $this->actingAs($person)->onPortal('sadmin')->patchJson('/commands/list-views/orders/'.$first, ['name' => 'Renamed'])->assertOk();

        $this->actingAs($person)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => 'Renamed', 'filters' => ['status' => 'submitted']])
            ->assertOk()
            ->assertJsonCount(1, 'data.views')
            ->assertJsonPath('data.views.0.filters.status', 'submitted');
    }

    public function test_one_person_cannot_rename_another_persons_filter(): void
    {
        $owner = User::factory()->staff()->create();
        $stranger = User::factory()->staff()->create();
        $viewId = $this->saved($owner, 'Mine');

        $this->actingAs($stranger)->onPortal('sadmin')
            ->patchJson('/commands/list-views/orders/'.$viewId, ['name' => 'Stolen'])
            ->assertNotFound();

        $this->assertDatabaseHas('saved_list_filters', ['id' => $viewId, 'name' => 'Mine']);
    }

    public function test_a_filter_cannot_be_renamed_through_another_list(): void
    {
        $person = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Mine');

        $this->actingAs($person)->onPortal('sadmin')
            ->patchJson('/commands/list-views/deliveries/'.$viewId, ['name' => 'Moved'])
            ->assertNotFound();

        $this->assertDatabaseHas('saved_list_filters', ['id' => $viewId, 'name' => 'Mine']);
    }

    public function test_renaming_needs_the_same_permission_as_the_list(): void
    {
        $catalogOnly = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['catalog']]]);
        $dealer = User::factory()->dealer()->create();
        $person = User::factory()->staff()->create();
        $viewId = $this->saved($person, 'Mine');

        $this->actingAs($catalogOnly)->onPortal('manage')->patchJson('/commands/list-views/orders/'.$viewId, ['name' => 'No'])->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->patchJson('/commands/list-views/orders/'.$viewId, ['name' => 'No'])->assertForbidden();
        $this->assertDatabaseHas('saved_list_filters', ['id' => $viewId, 'name' => 'Mine']);
    }

    /** @param array<string, string> $filters */
    private function saved(User $person, string $name, array $filters = ['status' => 'held']): int
    {
        $this->actingAs($person)->onPortal('sadmin')
            ->postJson('/commands/list-views/orders', ['name' => $name, 'filters' => $filters])
            ->assertOk();

        return (int) DB::table('saved_list_filters')->where('user_id', $person->id)->where('name', $name)->value('id');
    }
}
