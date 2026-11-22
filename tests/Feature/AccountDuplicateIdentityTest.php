<?php

namespace Tests\Feature;

use App\Domain\Accounts\IdentityKeys;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One person, one account (client, 2026-11-22): the same mobile number, email, NID number or birth-certificate
 * number cannot open a second account.
 */
class AccountDuplicateIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
    }

    /** @param  array<string, mixed>  $over */
    private function payload(string $division, array $over = []): array
    {
        return [
            'role' => 'dealer', 'identity_type' => 'nid', 'date_of_birth' => '1985-03-03', 'place_code' => $division, 'name' => 'Md. Rafiqul Islam', 'name_bn' => 'মোঃ রফিকুল ইসলাম',
            'email' => 'rafiq.one@gmail.com', 'phone' => '01712345678', 'father_name' => 'Late Abdur Rahman', 'mother_name' => 'Rahima Begum',
            'nid' => '19851234567890123', 'pharmacy' => 'Madina Care Point', 'division' => 'Dhaka', 'district' => 'Gazipur', 'upazila' => 'Kaliakair',
            'union' => 'Chapair', 'village' => 'Ratanpur', 'post_office' => 'Kaliakair-1750', 'latitude' => '24.0667000', 'longitude' => '90.2167000',
            'status' => 'active', 'reason' => 'Contract verified and authorised.',
            ...$over,
        ];
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
    }

    public function test_the_same_number_email_nid_or_birth_certificate_cannot_open_a_second_account(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC102'))->assertSessionHasNoErrors();
        $this->assertSame(1, User::query()->where('email', 'rafiq.one@gmail.com')->count());

        // Same mobile number (written another way), everything else new.
        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC103', ['email' => 'two@gmail.com', 'nid' => '29851234567890124', 'phone' => '+8801712345678']))
            ->assertSessionHasErrors('phone');
        // Same email in capitals.
        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC103', ['email' => 'RAFIQ.ONE@gmail.com', 'nid' => '29851234567890124', 'phone' => '01812345678']))
            ->assertSessionHasErrors('email');
        // Same NID number, written with Bangla digits and spaces.
        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC103', ['email' => 'three@gmail.com', 'nid' => '১৯৮৫ ১২৩৪৫৬৭ ৮৯০১২৩', 'phone' => '01812345679']))
            ->assertSessionHasErrors('nid');

        $this->assertSame(1, User::query()->where('role', 'dealer')->count());
    }

    public function test_the_same_birth_certificate_number_cannot_open_a_second_account(): void
    {
        $admin = $this->admin();
        $birth = ['identity_type' => 'birth_certificate', 'birth_certificate_number' => '20051234567890123', 'nid' => ''];
        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC102', $birth))->assertSessionHasNoErrors();

        $this->actingAs($admin)->post('/commands/accounts', $this->payload('MNHC103', [...$birth, 'email' => 'other@gmail.com', 'phone' => '01812345678']))
            ->assertSessionHasErrors('birth_certificate_number');
        $this->assertSame(1, User::query()->where('role', 'dealer')->count());
    }

    public function test_an_account_keeps_its_own_number_when_edited_but_cannot_take_anothers(): void
    {
        $admin = $this->admin();
        $first = User::factory()->create(['role' => 'dealer', 'nid' => '19851234567890123']);
        $second = User::factory()->create(['role' => 'dealer', 'nid' => '29851234567890124']);

        $this->assertNull(IdentityKeys::holder(IdentityKeys::Nid, '19851234567890123', $first->id));
        $this->assertSame($first->id, IdentityKeys::holder(IdentityKeys::Nid, '19851234567890123', $second->id));

        $this->actingAs($admin)->onPortal('sadmin')->patch('/commands/accounts/'.$second->id, ['nid' => '19851234567890123', 'reason' => 'Corrected on the paper form.'])
            ->assertSessionHasErrors('nid');
        $this->assertNotNull($second->refresh()->nid_hash);
    }

    public function test_the_key_shows_nothing_of_the_number(): void
    {
        $user = User::factory()->create(['role' => 'dealer', 'nid' => '19851234567890123']);

        $this->assertStringNotContainsString('1985', (string) $user->nid_hash);
        $this->assertSame(64, strlen((string) $user->nid_hash));
        $this->assertArrayNotHasKey('nid_hash', $user->toArray());
    }
}
