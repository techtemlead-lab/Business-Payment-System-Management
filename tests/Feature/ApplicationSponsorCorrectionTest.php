<?php

namespace Tests\Feature;

use App\Domain\Staff\StaffOperations;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reviewer of a partner application can correct who referred the applicant.
 * The person named in the correction must be the sponsor, or the referral commission
 * goes to the wrong dealer.
 */
class ApplicationSponsorCorrectionTest extends TestCase
{
    use RefreshDatabase;

    private User $reviewer;

    private User $firstSponsor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedClassicPlaceCodes();
        $this->reviewer = User::factory()->staff()->create();
        $this->firstSponsor = User::factory()->dealer()->create(['dealer_code' => 'SPONSOR-A']);
    }

    public function test_a_corrected_sponsor_code_replaces_the_sponsor_picked_on_the_application(): void
    {
        $correctSponsor = User::factory()->dealer()->create(['dealer_code' => 'SPONSOR-B']);
        $id = $this->application();

        app(StaffOperations::class)->review($this->reviewer, $id, 'approved', 'Checked the referral with the dealer', null, ['sponsor_code' => 'SPONSOR-B']);

        $this->assertSame($correctSponsor->id, $this->applicant()->sponsor_id);
        $this->assertDatabaseHas('referral_tree_paths', ['ancestor_id' => $correctSponsor->id, 'descendant_id' => $this->applicant()->id, 'depth' => 1]);
        $this->assertDatabaseMissing('referral_tree_paths', ['ancestor_id' => $this->firstSponsor->id, 'descendant_id' => $this->applicant()->id]);
    }

    public function test_leaving_the_correction_empty_keeps_the_sponsor_on_the_application(): void
    {
        $id = $this->application();

        app(StaffOperations::class)->review($this->reviewer, $id, 'approved', 'Checked the application details', null, ['sponsor_code' => null]);

        $this->assertSame($this->firstSponsor->id, $this->applicant()->sponsor_id);
    }

    private function applicant(): User
    {
        return User::query()->where('email', 'applicant@example.test')->firstOrFail();
    }

    private function application(): string
    {
        $id = (string) Str::uuid();
        DB::table('dealer_applications')->insert([
            'id' => $id,
            'submitted_by' => User::factory()->staff('sr')->create()->id,
            'email' => 'applicant@example.test',
            'data' => json_encode([
                'name' => 'Applicant Dealer',
                'email' => 'applicant@example.test',
                'phone' => '01700000011',
                'role' => 'dealer',
                'place_code' => 'MNHC-102',
                'sponsor_id' => $this->firstSponsor->id,
            ], JSON_THROW_ON_ERROR),
            'status' => 'pending',
            'source' => 'staff',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }
}
