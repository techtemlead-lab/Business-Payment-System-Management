<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Server form messages on Bangla pages are Bangla, with Bangla field names.
 */
class BanglaValidationMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_english_message_has_a_bangla_line_with_the_same_blanks(): void
    {
        $english = Arr::except(require base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/en/validation.php'), ['custom', 'attributes']);
        $bangla = Arr::except(require lang_path('bn/validation.php'), ['custom', 'attributes', 'values']);

        $englishLines = Arr::dot($english);
        $banglaLines = Arr::dot($bangla);
        $this->assertSame([], array_values(array_diff(array_keys($englishLines), array_keys($banglaLines))), 'Every English rule needs a Bangla line.');
        $this->assertSame([], array_values(array_diff(array_keys($banglaLines), array_keys($englishLines))), 'No Bangla line should exist for a rule English does not have.');

        foreach ($englishLines as $key => $line) {
            $this->assertSame($this->blanksIn($line), $this->blanksIn($banglaLines[$key]), "The blanks in [$key] must match English.");
            $this->assertMatchesRegularExpression('/\p{Bengali}/u', $banglaLines[$key], "[$key] must be written in Bangla.");
        }
    }

    public function test_the_money_format_message_from_the_company_funds_form_is_bangla(): void
    {
        $finance = User::factory()->staff('finance')->create(['locale' => 'bn']);

        $this->actingAs($finance)->onPortal('manage')
            ->post('/commands/operations/company-funds/spends', ['amount' => 'twelve'])
            ->assertSessionHasErrors(['amount' => 'টাকার পরিমাণ সঠিকভাবে লেখা হয়নি।']);
    }

    public function test_the_place_message_from_the_events_form_is_bangla_with_both_field_names(): void
    {
        $editor = User::factory()->staff('editor')->create(['locale' => 'bn']);

        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/events', ['title_en' => 'Meeting'])
            ->assertSessionHasErrors(['place' => 'অনলাইন লিংক না থাকলে স্থান দিতে হবে।']);
    }

    public function test_english_pages_keep_english_messages(): void
    {
        $editor = User::factory()->staff('editor')->create(['locale' => 'en']);

        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/events', ['title_en' => 'Meeting'])
            ->assertSessionHasErrors(['place' => 'The place field is required when online url is not present.']);
    }

    public function test_a_field_inside_a_list_gets_its_bangla_name(): void
    {
        app()->setLocale('bn');

        $messages = Validator::make(
            ['lines' => [['unit_cost' => 'abc']]],
            ['lines.*.unit_cost' => ['numeric']],
        )->errors();

        $this->assertSame('একক ক্রয়মূল্য সংখ্যায় লিখতে হবে।', $messages->first('lines.0.unit_cost'));
    }

    /**
     * @return list<string>
     */
    private function blanksIn(string $line): array
    {
        preg_match_all('/:[a-z]+/', $line, $matches);
        $blanks = array_values(array_unique($matches[0]));
        sort($blanks);

        return $blanks;
    }
}
