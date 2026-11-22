<?php

namespace Tests\Feature;

use App\Support\PortalHost;
use App\Support\SiteCustomization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pages that open on a portal host (dealer., subdealer., manage., sadmin.) must send a visitor
 * to the public website on the public host. A relative link stays on the portal, where
 * /products or /partnership do not exist and a guest is sent back to the sign-in page.
 */
class PortalPublicLinksTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private const PublicPaths = ['/', '/products', '/about', '/contact', '/dealers', '/partnership', '/services', '/faq', '/login', '/notice', '/prescribers'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        SiteCustomization::reset();
    }

    /**
     * @return list<string>
     */
    private function hrefs(string $html): array
    {
        preg_match_all('/<a\b[^>]*\shref="([^"]*)"/i', $html, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function partnerPortals(): array
    {
        return ['dealer' => ['dealer'], 'subdealer' => ['subdealer']];
    }

    #[DataProvider('partnerPortals')]
    public function test_the_partner_sign_in_page_links_to_the_public_site_on_the_public_host(string $portal): void
    {
        $hrefs = $this->hrefs($this->onPortal($portal)->get('/login')->assertOk()->getContent());

        foreach (['/', '/products', '/about', '/contact', '/dealers', '/partnership', '/services', '/faq', '/login'] as $path) {
            $this->assertContains(PortalHost::url('public', $path), $hrefs, "The sign-in page on {$portal}. has no public link to {$path}.");
        }
        $relativePublic = array_values(array_intersect($hrefs, self::PublicPaths));
        $this->assertSame([], $relativePublic, "The sign-in page on {$portal}. keeps a visitor on the portal host.");
        // Moving around inside the portal stays relative.
        $this->assertContains('/forgot-password', $hrefs);
    }

    public function test_the_partner_forgot_password_page_also_leaves_for_the_public_site(): void
    {
        $hrefs = $this->hrefs($this->onPortal('dealer')->get('/forgot-password')->assertOk()->getContent());

        $this->assertContains(PortalHost::url('public', '/products'), $hrefs);
        // "Back to sign in" is a step inside the portal, so it stays relative; the public pages do not.
        $this->assertContains('/login', $hrefs);
        $this->assertSame([], array_values(array_intersect($hrefs, array_diff(self::PublicPaths, ['/login']))));
    }

    #[DataProvider('partnerPortals')]
    public function test_the_phone_menu_of_the_sign_in_page_opens_public_pages_on_the_public_host(string $portal): void
    {
        $html = $this->onPortal($portal)->get('/login')->assertOk()->getContent();
        $drawer = substr($html, (int) strpos($html, 'id="refMobileDrawer"'));
        $drawer = substr($drawer, 0, (int) strpos($drawer, '</aside>'));

        $this->assertStringContainsString('href="'.PortalHost::url('public', '/login').'"', $drawer);
        $this->assertStringContainsString('href="'.PortalHost::url('public', '/partnership').'"', $drawer);
        $this->assertSame([], array_values(array_intersect($this->hrefs($drawer), self::PublicPaths)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function officePortals(): array
    {
        return ['manage' => ['manage'], 'sadmin' => ['sadmin']];
    }

    #[DataProvider('officePortals')]
    public function test_the_office_sign_in_pages_have_no_link_that_stays_on_the_portal_for_a_public_page(string $portal): void
    {
        $hrefs = $this->hrefs($this->onPortal($portal)->get('/login')->assertOk()->getContent());

        $this->assertSame([], array_values(array_intersect($hrefs, ['/', '/products', '/about', '/contact', '/dealers', '/partnership'])));
    }

    public function test_the_not_found_page_on_a_portal_sends_the_contact_link_to_the_public_site(): void
    {
        $html = $this->onPortal('dealer')->get('/no-such-page')->assertNotFound()->getContent();

        $this->assertContains(PortalHost::url('public', '/contact'), $this->hrefs($html));
    }

    public function test_a_page_on_the_public_host_keeps_plain_paths(): void
    {
        $hrefs = $this->hrefs($this->onPortal('public')->get('/about')->assertOk()->getContent());

        foreach (['/', '/products', '/contact', '/login'] as $path) {
            $this->assertContains($path, $hrefs);
        }
        $this->assertNotContains(PortalHost::url('public', '/products'), $hrefs);
    }

    public function test_a_single_host_local_build_keeps_plain_paths(): void
    {
        $this->assertTrue(PortalHost::allowsSharedPreview());

        $hrefs = $this->hrefs($this->get('http://localhost/about')->assertOk()->getContent());

        foreach (['/', '/products', '/contact', '/login'] as $path) {
            $this->assertContains($path, $hrefs);
        }
    }

    public function test_the_public_path_helper_leaves_complete_addresses_alone(): void
    {
        $this->onPortal('dealer')->get('/login')->assertOk();

        $this->assertSame('https://example.com/about', PortalHost::publicPath('https://example.com/about'));
        $this->assertSame('mailto:office@example.com', PortalHost::publicPath('mailto:office@example.com'));
        $this->assertSame('tel:+8801700000000', PortalHost::publicPath('tel:+8801700000000'));
        $this->assertSame('#partner-apply', PortalHost::publicPath('#partner-apply'));
        $this->assertSame('//cdn.example.com/a.png', PortalHost::publicPath('//cdn.example.com/a.png'));
        $this->assertSame(PortalHost::url('public', '/about'), PortalHost::publicPath('about'));
        $this->assertSame(PortalHost::url('public', '/contact#vendor'), PortalHost::publicPath('/contact#vendor'));
    }

    public function test_a_footer_link_typed_as_a_full_address_is_kept_as_typed_on_a_portal_page(): void
    {
        SiteCustomization::save(['footer_link_about_url' => 'https://madinanhc.com/about']);

        $hrefs = $this->hrefs($this->onPortal('dealer')->get('/login')->assertOk()->getContent());

        $this->assertContains('https://madinanhc.com/about', $hrefs);
        $this->assertNotContains('/https://madinanhc.com/about', $hrefs);
    }
}
