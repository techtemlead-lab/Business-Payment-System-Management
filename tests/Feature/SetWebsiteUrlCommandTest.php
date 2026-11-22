<?php

namespace Tests\Feature;

use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * madina:set-website-url changes a saved website setting that is only the company domain to
 * https://www.madinanhc.com, leaves every other value alone, writes only with --apply and is safe to run twice.
 */
class SetWebsiteUrlCommandTest extends TestCase
{
    use RefreshDatabase;

    private const Printed = 'https://www.madinanhc.com';

    public function test_the_command_lists_and_does_not_write_without_apply(): void
    {
        $this->storeSettings(['footer_website' => 'madinanhc.com', 'contact_email' => 'info@madinanhc.com']);
        $before = $this->storedBody();

        $this->artisan('madina:set-website-url')->expectsOutputToContain('would change')->assertSuccessful();

        $this->assertSame($before, $this->storedBody());
    }

    public function test_apply_changes_only_values_that_are_the_bare_company_domain_and_a_second_run_finds_nothing(): void
    {
        $this->storeSettings([
            'footer_website' => 'madinanhc.com',
            'other_site' => 'https://madinanhc.com/',
            'with_www' => 'www.madinanhc.com',
            'contact_email' => 'info@madinanhc.com',
            'page_link' => 'https://madinanhc.com/about',
            'sentence' => 'Visit madinanhc.com today',
            'someone_else' => 'example.org',
            'nested' => ['site' => 'http://madinanhc.com'],
        ]);

        $this->artisan('madina:set-website-url --apply')->expectsOutputToContain('4 setting(s) changed')->assertSuccessful();

        $stored = json_decode($this->storedBody(), true);
        $this->assertSame(self::Printed, $stored['footer_website']);
        $this->assertSame(self::Printed, $stored['other_site']);
        $this->assertSame(self::Printed, $stored['with_www']);
        $this->assertSame(self::Printed, $stored['nested']['site']);
        $this->assertSame('info@madinanhc.com', $stored['contact_email']);
        $this->assertSame('https://madinanhc.com/about', $stored['page_link']);
        $this->assertSame('Visit madinanhc.com today', $stored['sentence']);
        $this->assertSame('example.org', $stored['someone_else']);
        $this->assertDatabaseHas('audit_events', ['action' => 'site.website_url_normalised']);

        $after = $this->storedBody();
        $this->artisan('madina:set-website-url --apply')->expectsOutputToContain('Nothing to change')->assertSuccessful();
        $this->assertSame($after, $this->storedBody());
    }

    public function test_the_command_is_harmless_when_no_settings_were_ever_saved(): void
    {
        $this->artisan('madina:set-website-url --apply')->expectsOutputToContain('No saved website settings')->assertSuccessful();
    }

    /** @param  array<string, mixed>  $values */
    private function storeSettings(array $values): void
    {
        DB::table('content_pages')->updateOrInsert(['slug' => SiteCustomization::SLUG], [
            'title' => 'Frontend Customization',
            'body' => json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'published' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function storedBody(): string
    {
        return (string) DB::table('content_pages')->where('slug', SiteCustomization::SLUG)->value('body');
    }
}
