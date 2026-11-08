<?php

namespace Tests\Feature;

use App\Domain\Content\TrainingLibrary;
use App\Models\SalesKitItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SalesKitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake(TrainingLibrary::diskName());
    }

    public function test_owner_uploads_and_partners_view_and_download(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/sales-kit', [
            'title' => 'Winter poster',
            'kind' => 'poster',
            'language' => 'bn',
            'caption' => 'Share on Facebook',
            'file' => UploadedFile::fake()->create('poster.pdf', 200, 'application/pdf'),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $item = SalesKitItem::query()->sole();
        Storage::disk(TrainingLibrary::diskName())->assertExists($item->file_path);

        foreach ([[$dealer, 'dealer'], [$subDealer, 'subdealer']] as [$partner, $portal]) {
            $this->actingAs($partner)->onPortal($portal)->get('/sales-kit')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->where('data.sales_kit.can_manage', false)
                    ->where('data.sales_kit.items.0.title', 'Winter poster')
                    ->where('data.sales_kit.items.0.download_url', '/sales-kit/'.$item->id.'/file'));
            $this->actingAs($partner)->onPortal($portal)->get('/sales-kit/'.$item->id.'/file')
                ->assertOk()
                ->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_only_the_owner_can_add_or_remove(): void
    {
        $dealer = User::factory()->dealer()->create();
        $staff = User::factory()->create(['role' => 'employee', 'status' => 'active']);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $item = SalesKitItem::query()->create(['title' => 'Old', 'kind' => 'poster', 'language' => 'en', 'file_path' => 'sales-kit/old.pdf', 'file_name' => 'old.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $owner->id]);

        $upload = ['title' => 'X', 'kind' => 'poster', 'language' => 'en', 'file' => UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')];
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/sales-kit', $upload)->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->delete('/commands/sales-kit/'.$item->id)->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->post('/commands/sales-kit', $upload)->assertForbidden();
        $this->actingAs($staff)->onPortal('sadmin')->get('/sales-kit')->assertForbidden();
        $this->assertSame(1, SalesKitItem::query()->count());

        Storage::disk(TrainingLibrary::diskName())->put('sales-kit/old.pdf', 'pdf');
        $this->actingAs($owner)->onPortal('sadmin')->delete('/commands/sales-kit/'.$item->id)->assertRedirect();
        $this->assertSame(0, SalesKitItem::query()->count());
        Storage::disk(TrainingLibrary::diskName())->assertMissing('sales-kit/old.pdf');
        $this->assertDatabaseHas('audit_events', ['action' => 'sales_kit.removed']);
    }

    public function test_an_unsupported_file_type_is_refused(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/sales-kit', [
            'title' => 'Script', 'kind' => 'poster', 'language' => 'en',
            'file' => UploadedFile::fake()->create('evil.html', 10, 'text/html'),
        ])->assertSessionHasErrors('file');
        $this->assertSame(0, SalesKitItem::query()->count());
    }

    public function test_a_stranger_cannot_fetch_the_file(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $item = SalesKitItem::query()->create(['title' => 'Old', 'kind' => 'poster', 'language' => 'en', 'file_path' => 'sales-kit/old.pdf', 'file_name' => 'old.pdf', 'mime' => 'application/pdf', 'size' => 1, 'uploaded_by' => $owner->id]);

        $this->onPortal('dealer')->get('/sales-kit/'.$item->id.'/file')->assertRedirect();
    }
}
