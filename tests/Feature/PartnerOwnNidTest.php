<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerKycDocuments;
use App\Domain\Payouts\ManualPayoutService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** R5 (decision D12 kept): a partner adds his own NID; staff verify it; only then does the first withdrawal open. */
class PartnerOwnNidTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function birthRegistrationPartner(): User
    {
        // Joined with a birth registration and no number on file (the office takes the number when it opens the account).
        return User::factory()->create(['role' => 'dealer', 'status' => 'active', 'nid' => null, 'profile' => ['identity_type' => 'birth_certificate']]);
    }

    public function test_a_birth_certificate_number_taken_at_account_opening_is_enough_to_withdraw(): void
    {
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active', 'nid' => null, 'profile' => ['identity_type' => 'birth_certificate', 'birth_certificate_number' => '19900000000000001']]);

        $this->assertFalse(app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner));
    }

    private function send(User $partner, string $nid = '1234567890'): TestResponse
    {
        return $this->actingAs($partner)->onPortal('dealer')->post('/commands/profile/nid', ['nid' => $nid, 'photo' => UploadedFile::fake()->image('nid.jpg', 600, 400)]);
    }

    private function reviewer(): User
    {
        return User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts', 'identity_write']]]);
    }

    public function test_a_sent_nid_waits_and_does_not_open_the_first_withdrawal(): void
    {
        $partner = $this->birthRegistrationPartner();
        $this->assertTrue(app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner));

        $this->send($partner)->assertRedirect();

        $row = DB::table('kyc_documents')->where('user_id', $partner->id)->first();
        $this->assertSame('pending', $row->review_status);
        $this->assertNotSame('1234567890', $row->claimed_nid, 'the number is stored encrypted');
        $this->assertNull($partner->fresh()->nid);
        $this->assertTrue(app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner->fresh()));
        $this->assertSame('pending', app(PartnerKycDocuments::class)->nidRequestState($partner)['status']);
        Storage::disk('local')->assertExists($row->disk_path);
    }

    public function test_a_bad_number_and_a_second_send_while_waiting_are_refused(): void
    {
        $partner = $this->birthRegistrationPartner();

        $this->send($partner, '12345')->assertSessionHasErrors('nid');
        $this->assertSame(0, DB::table('kyc_documents')->count());
        $this->send($partner, '১২৩৪৫৬৭৮৯০')->assertSessionHasNoErrors();
        $this->send($partner, '1234567890')->assertSessionHasErrors('nid');
        $this->assertSame(1, DB::table('kyc_documents')->count());
    }

    public function test_staff_verifying_it_puts_the_number_on_the_account_and_opens_the_withdrawal(): void
    {
        $partner = $this->birthRegistrationPartner();
        $this->send($partner);
        $documentId = (string) DB::table('kyc_documents')->value('id');

        $listed = $this->actingAs($this->reviewer())->onPortal('manage')->getJson('/commands/accounts/'.$partner->id.'/kyc-documents')->assertOk()->json('data.0');
        $this->assertSame('1234567890', $listed['claimed_nid']);

        $this->actingAs($this->reviewer())->onPortal('manage')
            ->post('/commands/accounts/'.$partner->id.'/kyc-documents/'.$documentId.'/review', ['decision' => 'approve'])->assertRedirect();

        $this->assertSame('1234567890', $partner->fresh()->nid);
        $this->assertFalse(app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner->fresh()));
        $this->assertNull(DB::table('kyc_documents')->where('id', $documentId)->value('claimed_nid'));
        $this->assertSame('verified', app(PartnerKycDocuments::class)->nidRequestState($partner->fresh())['status']);
    }

    public function test_sending_it_back_needs_a_reason_and_lets_the_partner_send_again(): void
    {
        $partner = $this->birthRegistrationPartner();
        $this->send($partner);
        $documentId = (string) DB::table('kyc_documents')->value('id');
        $reviewer = $this->reviewer();

        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/accounts/'.$partner->id.'/kyc-documents/'.$documentId.'/review', ['decision' => 'send_back'])->assertSessionHasErrors('note');
        $this->actingAs($reviewer)->onPortal('manage')->post('/commands/accounts/'.$partner->id.'/kyc-documents/'.$documentId.'/review', ['decision' => 'send_back', 'note' => 'ছবি ঝাপসা'])->assertRedirect();

        $state = app(PartnerKycDocuments::class)->nidRequestState($partner->fresh());
        $this->assertSame(['status' => 'rejected', 'note' => 'ছবি ঝাপসা'], $state);
        $this->assertTrue(app(ManualPayoutService::class)->needsNidBeforeFirstWithdrawal($partner->fresh()));
        $this->send($partner)->assertSessionHasNoErrors();
    }

    public function test_only_identity_staff_can_review_and_a_partner_cannot_verify_himself(): void
    {
        $partner = $this->birthRegistrationPartner();
        $this->send($partner);
        $documentId = (string) DB::table('kyc_documents')->value('id');
        $plainStaff = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['accounts']]]);

        $this->actingAs($plainStaff)->onPortal('manage')->post('/commands/accounts/'.$partner->id.'/kyc-documents/'.$documentId.'/review', ['decision' => 'approve'])->assertForbidden();
        $this->assertContains($this->actingAs($partner)->onPortal('dealer')->post('/commands/accounts/'.$partner->id.'/kyc-documents/'.$documentId.'/review', ['decision' => 'approve'])->status(), [403, 404]);
        $this->assertNull($partner->fresh()->nid);
    }
}
