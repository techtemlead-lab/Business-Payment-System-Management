<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * CR-06: the English-to-Bangla model runs in the office browser. The files are put on the server by an explicit
 * install step, each checked against its pinned checksum, and handed only to signed-in office staff.
 */
class BanglaModelTest extends TestCase
{
    use RefreshDatabase;

    private const Url = '/commands/bangla-model/en-bn/tokenizer_meta.json';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /**
     * @param  array<string, string>  $files  file => contents
     */
    private function pinFiles(array $files): void
    {
        config(['madina.bangla_model' => [
            'repository' => 'example/indictrans2-test',
            'revision' => 'abc123',
            'directory' => 'bangla-model/en-bn',
            'files' => array_map(fn (string $contents): string => hash('sha256', $contents), $files),
        ]]);
    }

    public function test_office_staff_get_an_installed_model_file(): void
    {
        Storage::disk('local')->put('bangla-model/en-bn/tokenizer_meta.json', '{"unk_id":3}');

        $response = $this->actingAs(User::factory()->create(['role' => 'inventory']))->get(self::Url)->assertOk();

        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('{"unk_id":3}', file_get_contents($response->baseResponse->getFile()->getPathname()));
    }

    public function test_dealers_and_visitors_do_not_get_the_model(): void
    {
        Storage::disk('local')->put('bangla-model/en-bn/tokenizer_meta.json', '{}');

        $this->actingAs(User::factory()->create(['role' => 'dealer', 'status' => 'active']))->get(self::Url)->assertForbidden();
        auth()->logout();
        $this->get(self::Url)->assertStatus(302);
    }

    public function test_a_server_without_the_model_says_it_is_not_there_so_the_form_stays_manual(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'inventory']))->get(self::Url)->assertNotFound();
    }

    public function test_only_the_pinned_model_files_can_be_fetched(): void
    {
        Storage::disk('local')->put('bangla-model/en-bn/secret.txt', 'private');
        Storage::disk('local')->put('product-photo-model/cutout/config.json', '{}');
        $staff = User::factory()->create(['role' => 'inventory']);

        $this->actingAs($staff)->get('/commands/bangla-model/en-bn/secret.txt')->assertNotFound();
        $this->actingAs($staff)->get('/commands/bangla-model/en-bn/..%2F..%2Fproduct-photo-model%2Fcutout%2Fconfig.json')->assertNotFound();
    }

    public function test_the_install_step_puts_each_checked_file_in_place(): void
    {
        $this->pinFiles(['tokenizer_meta.json' => '{"unk_id":3}', 'encoder_model.onnx' => 'onnx-bytes']);
        Http::fake([
            'huggingface.co/example/indictrans2-test/resolve/abc123/tokenizer_meta.json' => Http::response('{"unk_id":3}'),
            'huggingface.co/example/indictrans2-test/resolve/abc123/encoder_model.onnx' => Http::response('onnx-bytes'),
        ]);

        $this->artisan('madina:install-bangla-model')->assertSuccessful();

        $this->assertSame('{"unk_id":3}', Storage::disk('local')->get('bangla-model/en-bn/tokenizer_meta.json'));
        $this->assertSame('onnx-bytes', Storage::disk('local')->get('bangla-model/en-bn/encoder_model.onnx'));

        $this->artisan('madina:install-bangla-model')->expectsOutputToContain('already in place')->assertSuccessful();
        Http::assertSentCount(2);
    }

    public function test_a_changed_or_damaged_download_is_never_installed(): void
    {
        $this->pinFiles(['encoder_model.onnx' => 'the-real-bytes']);
        Http::fake(['huggingface.co/*' => Http::response('something-else')]);

        $this->artisan('madina:install-bangla-model')->assertFailed();

        Storage::disk('local')->assertMissing('bangla-model/en-bn/encoder_model.onnx');
        Storage::disk('local')->assertMissing('bangla-model/en-bn/encoder_model.onnx.partial');
    }

    public function test_the_pinned_files_are_the_ones_the_browser_loads(): void
    {
        $files = array_keys(config('madina.bangla_model.files'));
        $browser = (string) file_get_contents(resource_path('js/shared/banglaFill/banglaModel.ts'));

        foreach (['encoder_model.onnx', 'encoder_model.onnx.data', 'decoder_model.onnx', 'decoder_with_past_model.onnx', 'decoder_shared.onnx.data', 'tokenizer_src.json', 'tokenizer_tgt.json', 'tokenizer_meta.json', 'tokenizer_config.json'] as $file) {
            $this->assertContains($file, $files);
            $this->assertStringContainsString("'{$file}'", $browser);
        }
        $this->assertStringContainsString(substr((string) config('madina.bangla_model.revision'), 0, 8), $browser, 'Bump the browser cache name when the model revision changes.');
    }
}
