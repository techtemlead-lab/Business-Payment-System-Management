<?php

namespace Tests\Feature;

use App\Jobs\ApplyDataImport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The reason shown when a background import stops follows the reader's language.
 */
class FailedImportReasonLocaleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_failed_import_reason_follows_the_page_language(): void
    {
        $admin = User::factory()->staff()->create();
        $id = (string) Str::uuid();
        DB::table('data_import_batches')->insert([
            'id' => $id, 'type' => 'partners', 'status' => 'queued', 'filename' => 'source.xlsx', 'file_path' => 'data-imports/'.$id.'.xlsx',
            'file_hash' => str_repeat('c', 64), 'source_reference' => 'Approved register', 'rows' => '[]', 'summary' => '{}',
            'created_by' => $admin->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        (new ApplyDataImport($id, $admin->id, 'Background import review'))->failed(null);

        foreach (['bn', 'en'] as $locale) {
            $admin->forceFill(['locale' => $locale])->save();
            $this->actingAs($admin)
                ->getJson('/api/v1/operations/imports?batch='.$id)
                ->assertOk()
                ->assertJsonPath('data.preview.review_reason', __('portal.import_failed_reason', [], $locale));
        }
    }
}
