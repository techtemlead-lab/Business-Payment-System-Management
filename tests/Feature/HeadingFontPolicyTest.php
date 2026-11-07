<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bunny Fonts' stylesheet for the Instrument Serif headings points at font files on
 * jsDelivr's fontsource path; the browser blocked them until the policy allowed that path.
 */
class HeadingFontPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_font_policy_allows_bunny_and_the_fontsource_files_only(): void
    {
        $this->withoutVite();

        foreach (['public' => '/', 'sadmin' => '/login', 'dealer' => '/login'] as $portal => $path) {
            $request = $portal === 'public' ? $this : $this->onPortal($portal);
            $csp = (string) $request->get($path)->assertOk()->headers->get('Content-Security-Policy');

            $this->assertMatchesRegularExpression('/font-src[^;]*https:\/\/fonts\.bunny\.net/', $csp, $portal);
            $this->assertMatchesRegularExpression('/font-src[^;]*https:\/\/cdn\.jsdelivr\.net\/fontsource\//', $csp, $portal);
            $this->assertDoesNotMatchRegularExpression('/script-src[^;]*jsdelivr/', $csp, $portal);
            $this->assertDoesNotMatchRegularExpression('/style-src[^;]*jsdelivr/', $csp, $portal);
        }
    }
}
