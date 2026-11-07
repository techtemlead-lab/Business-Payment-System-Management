<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAge;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class PartnerAgeLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seedClassicPlaceCodes();
        config(['madina.sms.driver' => 'array', 'madina.sms.sent' => []]);
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00:00', 'Asia/Dhaka'));
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        parent::tearDown();
    }

    public function test_eighteen_today_is_old_enough_and_one_day_younger_is_not(): void
    {
        $this->assertSame('2008-11-07', PartnerAge::latestAllowedBirthDate());
        $this->assertTrue(PartnerAge::isAdult('2008-11-07'));
        $this->assertTrue(PartnerAge::isAdult('1980-01-01'));
        $this->assertFalse(PartnerAge::isAdult('2008-11-08'));
        $this->assertFalse(PartnerAge::isAdult('2030-01-01'));
        $this->assertFalse(PartnerAge::isAdult('not a date'));
        $this->assertFalse(PartnerAge::isAdult(null));
    }

    public function test_public_application_refuses_an_applicant_under_eighteen(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application(['date_of_birth' => '2008-11-08']))
            ->assertRedirect('/partnership')
            ->assertSessionHasErrors(['date_of_birth' => __('portal.acct_val_dob_under_age', ['years' => 18])]);
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application(['date_of_birth' => null]))
            ->assertSessionHasErrors('date_of_birth');
        $this->assertSame(0, DB::table('dealer_applications')->count());

        $this->onPortal('public')->post('/partnership/apply', $this->application(['date_of_birth' => '2008-11-07']))
            ->assertRedirect('/partnership')
            ->assertSessionHasNoErrors();
        $data = json_decode((string) DB::table('dealer_applications')->value('data'), true);
        $this->assertSame('2008-11-07', $data['date_of_birth']);
    }

    public function test_under_age_message_is_shown_in_bangla(): void
    {
        $this->onPortal('public')->withSession(['locale' => 'bn'])->from('/partnership')
            ->post('/partnership/apply', $this->application(['date_of_birth' => '2015-01-01']))
            ->assertSessionHasErrors(['date_of_birth' => __('portal.acct_val_dob_under_age', ['years' => 18], 'bn')]);
        $this->assertStringContainsString('১৮', __('portal.acct_dob_age_hint', [], 'bn'));
    }

    public function test_staff_cannot_create_a_dealer_or_sub_dealer_under_eighteen(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);

        $this->actingAs($admin)->onPortal('sadmin')->from('/accounts/create')->post('/commands/accounts', $this->dealerPayload(['date_of_birth' => '2010-05-01']))
            ->assertSessionHasErrors(['date_of_birth' => __('portal.acct_val_dob_under_age', ['years' => 18])]);
        $this->assertFalse(User::query()->where('email', 'young-dealer@example.com')->exists());

        $this->actingAs($admin)->onPortal('sadmin')->post('/commands/accounts', $this->dealerPayload(['date_of_birth' => '2008-11-07']))
            ->assertRedirect('/accounts');
        $this->assertSame('2008-11-07', User::query()->where('email', 'young-dealer@example.com')->firstOrFail()->profile['date_of_birth']);
    }

    public function test_approving_an_application_with_an_under_age_birth_date_is_refused(): void
    {
        $id = (string) Str::uuid();
        DB::table('dealer_applications')->insert([
            'id' => $id, 'submitted_by' => null, 'email' => 'minor@example.test', 'status' => 'pending', 'source' => 'public',
            'data' => json_encode(['name' => 'Minor', 'email' => 'minor@example.test', 'phone' => '01710004455', 'role' => 'dealer',
                'division' => 'Rangpur', 'district' => 'Dinajpur', 'date_of_birth' => '2012-02-02'], JSON_THROW_ON_ERROR),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($reviewer)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Approved after territory and documents review.',
        ])->assertUnprocessable()->assertJsonValidationErrors('date_of_birth');
        $this->assertFalse(User::query()->where('email', 'minor@example.test')->exists());
        $this->assertDatabaseHas('dealer_applications', ['id' => $id, 'status' => 'pending']);
    }

    public function test_approved_application_keeps_the_birth_date_on_the_profile(): void
    {
        $this->onPortal('public')->post('/partnership/apply', $this->application(['date_of_birth' => '1995-04-10']))->assertSessionHasNoErrors();
        $id = (string) DB::table('dealer_applications')->value('id');
        $reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($reviewer)->postJson('/api/v1/operations/staff/applications/'.$id.'/review', [
            'decision' => 'approved',
            'reason' => 'Approved after territory and documents review.',
        ])->assertOk();

        $this->assertSame('1995-04-10', User::query()->where('email', 'adult.applicant@example.test')->firstOrFail()->profile['date_of_birth']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function application(array $overrides = []): array
    {
        return [
            'name' => 'Adult Applicant',
            'email' => 'adult.applicant@example.test',
            'phone' => '01710005566',
            'role' => 'dealer',
            'division' => 'Rangpur',
            'district' => 'Dinajpur',
            'website' => '',
            ...$overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function dealerPayload(array $overrides = []): array
    {
        return [
            'role' => 'dealer',
            'identity_type' => 'nid',
            'nid' => '1985123456789',
            'name' => 'Young Dealer',
            'name_bn' => 'তরুণ ডিলার',
            'father_name' => 'Father',
            'mother_name' => 'Mother',
            'place_code' => 'MNHC-101',
            'phone' => '01710000888',
            'email' => 'young-dealer@example.com',
            'reason' => 'New dealer for the division',
            ...$overrides,
        ];
    }
}
