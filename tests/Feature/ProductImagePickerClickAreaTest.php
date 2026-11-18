<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * In the "Add new product" form the photo chooser must open only when the person clicks the
 * chooser itself. The whole row used to be one <label>, so a click on the empty space beside it
 * also opened the file picker. These tests read the page source to keep that from coming back.
 */
class ProductImagePickerClickAreaTest extends TestCase
{
    private function imageFieldSource(): string
    {
        $text = (string) file_get_contents(resource_path('js/components/ProductCreateModal.tsx'));
        $caption = strpos($text, "translate('portal', 'pcm_image')}</span>");
        $hint = strpos($text, "translate('portal', 'pcm_image_hint')");
        $this->assertNotFalse($caption, 'The product image field was not found.');
        $this->assertNotFalse($hint, 'The product image hint was not found.');

        // From the tag that opens the field (just before its caption) to the hint text under the chooser.
        return substr($text, max(0, $caption - 120), $hint - $caption + 120);
    }

    public function test_the_image_row_is_not_wrapped_in_a_label(): void
    {
        $this->assertStringNotContainsString('<label', $this->imageFieldSource());
    }

    public function test_the_file_input_still_has_an_accessible_name(): void
    {
        $this->assertMatchesRegularExpression('/<input[^>]*type="file"[^>]*aria-label=/', $this->imageFieldSource());
    }
}
