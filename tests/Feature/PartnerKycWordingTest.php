<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerCreateMedia;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerKycWordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_saved_message_names_the_paper_in_plain_words(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'locale' => 'bn']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->from('/accounts')
            ->post("/commands/accounts/{$dealer->id}/kyc-documents", [
                'kind' => 'nid_front',
                'document' => UploadedFile::fake()->create('card.pdf', 10, 'application/pdf'),
            ])
            ->assertSessionHas('success', __('portal.flash_kyc_stored', ['kind' => __('portal.kyc_kind_nid_front', [], 'bn')], 'bn'));
    }

    public function test_a_paper_added_with_a_new_account_is_noted_in_the_language_of_the_page(): void
    {
        Storage::fake('local');
        app()->setLocale('bn');
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        app(PartnerCreateMedia::class)->attachOptional($owner, $dealer, [
            'agreement' => UploadedFile::fake()->create('agreement.pdf', 10, 'application/pdf'),
        ]);

        $this->assertSame(__('portal.kyc_note_added_with_account', [], 'bn'), DB::table('kyc_documents')->where('user_id', $dealer->id)->value('note'));
    }
}
