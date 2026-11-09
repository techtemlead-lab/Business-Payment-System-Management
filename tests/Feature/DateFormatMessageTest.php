<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** A date that does not exist gets a plain reason, never the technical pattern. */
class DateFormatMessageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_date_that_does_not_exist_is_refused_in_plain_words(): void
    {
        foreach (['bn' => 'দিন/মাস/বছর', 'en' => 'DD/MM/YYYY'] as $locale => $plainWords) {
            $owner = User::factory()->staff()->create(['locale' => $locale]);
            $product = Product::factory()->create();

            $response = $this->actingAs($owner)->onPortal('sadmin')->from('/schemes')->post('/commands/schemes', [
                'title' => 'Bad date scheme',
                'starts_on' => '2026-02-31',
                'ends_on' => '2026-03-01',
                'product_ids' => [$product->id],
                'min_quantity' => 1,
                'benefit_type' => 'free_goods',
                'benefit_text' => 'Free goods',
            ]);

            $response->assertSessionHasErrors('starts_on');
            $message = session('errors')->first('starts_on');
            $this->assertStringContainsString($plainWords, $message);
            $this->assertStringNotContainsString('Y-m-d', $message);
        }
    }
}
