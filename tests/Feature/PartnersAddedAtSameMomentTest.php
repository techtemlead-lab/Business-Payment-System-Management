<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Two people adding a partner on one place at the same moment both pass the early checks.
 * The database keeps the first, and the second must get a plain refusal, not a server error.
 */
class PartnersAddedAtSameMomentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClassicPlaceCodes();
    }

    public function test_a_second_dealer_on_the_place_is_refused_in_plain_words(): void
    {
        $this->rivalSavesFirst(['role' => 'dealer', 'dealer_code' => 'MNHC-102', 'refer_code' => 'MNHC-102']);

        $this->addPartner(['place_code' => 'MNHC-102', 'email' => 'second@example.test', 'phone' => '01712000101'])
            ->assertSessionHasErrors(['place_code' => Lang::get('portal.acct_val_place_taken')]);

        $this->assertDatabaseMissing('users', ['email' => 'second@example.test']);
    }

    public function test_a_second_sub_dealer_with_the_same_serial_is_refused_in_plain_words(): void
    {
        $union = $this->unionCode();
        $this->rivalSavesFirst(['role' => 'sub_dealer', 'dealer_code' => $union.'-000001', 'refer_code' => $union.'-000001']);

        $this->addPartner(['role' => 'sub_dealer', 'place_code' => $union, 'email' => 'second-sub@example.test', 'phone' => '01712000102'])
            ->assertSessionHasErrors(['place_code' => Lang::get('portal.acct_val_sub_dealer_code_just_used')]);

        $this->assertDatabaseMissing('users', ['email' => 'second-sub@example.test']);
    }

    public function test_the_same_email_saved_at_the_same_moment_is_refused_in_plain_words(): void
    {
        $this->rivalSavesFirst(['role' => 'dealer', 'email' => 'same-moment@example.test', 'dealer_code' => 'D-RIVAL', 'refer_code' => 'D-RIVAL']);

        $this->addPartner(['place_code' => 'MNHC-102', 'email' => 'same-moment@example.test', 'phone' => '01712000103'])
            ->assertSessionHasErrors(['email' => Lang::get('portal.acct_val_email_taken')]);
    }

    /**
     * Saves the rival account just before the new partner reaches the database, after every early check has passed.
     *
     * @param  array<string, string>  $rival
     */
    private function rivalSavesFirst(array $rival): void
    {
        $saved = false;
        User::creating(function (User $user) use (&$saved, $rival): void {
            if ($saved || $user->name !== 'Second Partner') {
                return;
            }
            $saved = true;
            User::factory()->create(['status' => 'active', 'name' => 'First Partner', ...$rival]);
        });
    }

    /** @param array<string, string> $overrides */
    private function addPartner(array $overrides)
    {
        $owner = User::factory()->create(['role' => 'super_admin']);

        return $this->actingAs($owner)->onPortal('sadmin')->post('/commands/accounts', [
            'role' => 'dealer',
            'identity_type' => 'birth_certificate',
            'birth_certificate_number' => '19911234567890111',
            'date_of_birth' => '1991-02-02',
            'name' => 'Second Partner',
            'name_bn' => 'দ্বিতীয় পার্টনার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'reason' => 'Onboarding test',
            ...$overrides,
        ]);
    }

    private function unionCode(): string
    {
        $division = PlaceCode::query()->where('code', 'MNHC-102')->firstOrFail();
        $district = PlaceCode::factory()->district($division)->create(['code' => 'MNHC-371', 'name' => 'Race District']);
        $upazila = PlaceCode::factory()->upazila($district)->create(['code' => 'MNHC-471', 'name' => 'Race Upazila']);

        return (string) PlaceCode::factory()->union($upazila)->create(['code' => 'MNHC-571', 'name' => 'Race Union'])->code;
    }
}
