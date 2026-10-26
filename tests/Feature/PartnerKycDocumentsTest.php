<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerKycDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_identity_write_staff_can_upload_list_and_download_kyc_scans(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $staff = User::factory()->staff('employee')->create([
            'profile' => ['permissions' => ['accounts', 'identity_write']],
        ]);
        $partner = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000103',
        ]);

        $this->actingAs($staff)->onPortal('manage')
            ->post('/commands/accounts/'.$partner->id.'/kyc-documents', [
                'kind' => 'nid_front',
                'note' => 'Verified against paper copy',
                'document' => UploadedFile::fake()->image('nid-front.jpg', 600, 400),
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('kyc_documents', [
            'user_id' => $partner->id,
            'kind' => 'nid_front',
            'uploaded_by' => $staff->id,
        ]);
        $path = DB::table('kyc_documents')->where('user_id', $partner->id)->value('disk_path');
        Storage::disk('local')->assertExists($path);
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $partner->id,
            'title' => __('portal.notice_kyc_uploaded_title', [], 'bn'),
        ]);
        $sent = config('madina.sms.sent');
        $this->assertSame('account.kyc_uploaded', $sent[0]['event'] ?? null);
        $this->assertSame(
            __('sms.kyc_document_uploaded', [
                'kind' => __('portal.kyc_kind_nid_front', [], 'bn'),
            ], 'bn'),
            $sent[0]['message'] ?? null,
        );

        $list = $this->actingAs($staff)->onPortal('manage')
            ->getJson('/commands/accounts/'.$partner->id.'/kyc-documents')
            ->assertOk()
            ->json('data');
        $this->assertCount(1, $list);
        $this->assertSame('nid_front', $list[0]['kind']);

        $this->actingAs($staff)->onPortal('manage')
            ->get('/commands/accounts/'.$partner->id.'/kyc-documents/'.$list[0]['id'])
            ->assertOk();
    }

    public function test_accounts_staff_without_identity_write_cannot_upload_kyc(): void
    {
        $staff = User::factory()->staff('account_manager')->create();
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($staff)->onPortal('manage')
            ->post('/commands/accounts/'.$partner->id.'/kyc-documents', [
                'kind' => 'agreement',
                'document' => UploadedFile::fake()->create('agreement.pdf', 40, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('kyc_documents', 0);
    }

    public function test_executable_uploads_are_rejected_and_partner_cannot_read_another_partners_scan(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $partner = User::factory()->create(['role' => 'dealer', 'status' => 'active']);
        $other = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$partner->id.'/kyc-documents', [
                'kind' => 'other',
                'document' => UploadedFile::fake()->create('scan.php', 20, 'application/x-httpd-php'),
            ])
            ->assertSessionHasErrors('document');

        $this->actingAs($admin)->onPortal('sadmin')
            ->post('/commands/accounts/'.$partner->id.'/kyc-documents', [
                'kind' => 'photo',
                'document' => UploadedFile::fake()->image('face.jpg'),
            ])
            ->assertRedirect();

        $id = (string) DB::table('kyc_documents')->where('user_id', $partner->id)->value('id');
        $this->actingAs($admin)->onPortal('sadmin')
            ->get('/commands/accounts/'.$other->id.'/kyc-documents/'.$id)
            ->assertNotFound();
    }
}
