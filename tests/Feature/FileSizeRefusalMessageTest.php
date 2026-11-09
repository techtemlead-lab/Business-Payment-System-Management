<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** A file that is too big gets the limit in MB, the way people talk about it, never in kilobytes. */
class FileSizeRefusalMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_file_over_the_limit_is_refused_in_megabytes(): void
    {
        Storage::fake('local');

        foreach (['bn' => '৫ এমবি', 'en' => '5 MB'] as $locale => $plainLimit) {
            $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => $locale]);

            $this->actingAs($dealer)->onPortal('dealer')->from('/messages')->post('/commands/messages', [
                'body' => 'A photo that is too big',
                'photo' => UploadedFile::fake()->create('big.png', 6000, 'image/png'),
            ])->assertSessionHasErrors('photo');

            $message = (string) session('errors')->first('photo');
            $this->assertStringContainsString($plainLimit, $message);
            $this->assertStringNotContainsString('5120', $message);
            $this->assertStringNotContainsString('কিলোবাইট', $message);
            $this->assertStringNotContainsString('kilobyte', $message);
        }
    }

    public function test_a_limit_on_text_length_still_shows_its_number(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'locale' => 'en']);

        $this->actingAs($dealer)->onPortal('dealer')->from('/messages')->post('/commands/messages', [
            'body' => str_repeat('a', 5000),
        ])->assertSessionHasErrors('body');

        $this->assertMatchesRegularExpression('/\d+/', (string) session('errors')->first('body'));
    }
}
