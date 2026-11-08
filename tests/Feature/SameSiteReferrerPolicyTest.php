<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * After a save the person lands back on the page in the same tab, not on whichever page
 * was opened last in another tab. That needs the browser to send the page address on our
 * own site, and nothing to other sites.
 */
class SameSiteReferrerPolicyTest extends TestCase
{
    use RefreshDatabase;

    private const SamePolicy = 'same-origin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_pages_portal_pages_and_redirects_send_the_policy(): void
    {
        $owner = User::factory()->staff('super_admin')->create();

        $this->onPortal('public')->get('/')->assertHeader('Referrer-Policy', self::SamePolicy);
        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk()->assertHeader('Referrer-Policy', self::SamePolicy);
        $this->actingAs($owner)->onPortal('sadmin')->from('/')->post('/commands/events', [])->assertRedirect()->assertHeader('Referrer-Policy', self::SamePolicy);
    }

    public function test_a_save_returns_to_the_page_in_this_tab_not_the_last_page_opened_elsewhere(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $root = 'http://manage.example.test';

        $response = $this->actingAs($editor)->onPortal('manage')
            ->withSession(['_previous' => ['url' => $root.'/branch-performance']])
            ->withHeader('Referer', $root.'/events')
            ->post('/commands/events', [
                'title_bn' => 'মাসিক সভা',
                'title_en' => 'Monthly meeting',
                'date' => now('Asia/Dhaka')->addDays(3)->toDateString(),
                'start_time' => '10:00',
                'end_time' => '12:00',
                'place' => 'Pirganj office',
                'audience' => 'all',
            ]);

        $response->assertSessionHasNoErrors()->assertRedirect($root.'/events');
    }

    public function test_no_web_server_file_sets_a_different_policy(): void
    {
        foreach ([base_path('infra/nginx.production.conf'), base_path('infra/preview.host-nginx.conf'), public_path('.htaccess')] as $file) {
            $this->assertFileExists($file);
            $this->assertDoesNotMatchRegularExpression('/referrer-policy["\s]+(?!"?same-origin)/i', (string) file_get_contents($file), basename($file).' must not set another Referrer-Policy.');
        }
    }
}
