<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class SharedPreviewPortalTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.login_otp.enabled', false);
    }

    public function test_loopback_login_keeps_owner_staff_dealer_and_subdealer_on_the_same_origin(): void
    {
        $response = $this->preview('GET', '/login');

        $response->assertOk()
            ->assertSee('Owner Login')
            ->assertSee('Staff Login')
            ->assertSee('Dealer Login')
            ->assertSee('Sub-dealer Login')
            ->assertSee('There is no separate supplier sign-in')
            ->assertSee('/preview-portal/sadmin?next=', false)
            ->assertSee('/preview-portal/manage?next=', false)
            ->assertSee('/preview-portal/dealer?next=', false)
            ->assertSee('/preview-portal/subdealer?next=', false)
            ->assertDontSee('sadmin.example.test')
            ->assertDontSee('dealer.localhost');
    }

    public function test_configured_public_host_still_links_portals_by_subdomain(): void
    {
        $this->onPortal('public')->get('/login')
            ->assertOk()
            ->assertSee(PortalHost::url('sadmin', '/login'), false)
            ->assertDontSee('/preview-portal/sadmin');

        $this->onPortal('public')->get('/owner/login')
            ->assertRedirect(PortalHost::url('sadmin', '/login'));
    }

    public function test_preview_directory_is_unavailable_on_a_portal_host(): void
    {
        $this->onPortal('public')->get('/portals')->assertNotFound();
        $this->onPortal('sadmin')->get('/preview-portal/dealer')->assertNotFound();
        $this->preview('GET', '/preview-portal/supplier')->assertNotFound();
    }

    #[DataProvider('portalSignIns')]
    public function test_shared_preview_sign_in_opens_the_selected_portal(string $portal, string $role, string $heading): void
    {
        $user = $this->portalUser($role);

        $this->preview('GET', '/preview-portal/'.$portal)
            ->assertRedirect('/login')
            ->assertPlainCookie(PortalHost::PreviewCookie, $portal);

        $this->withUnencryptedCookie(PortalHost::PreviewCookie, $portal);
        $this->preview('GET', '/login')
            ->assertOk()
            ->assertSee($heading);

        $this->preview('POST', '/login', [
            'email' => $user->email,
            'password' => 'SyntheticPassword123',
        ])->assertRedirect('/');

        $this->preview('GET', '/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('portal', $portal));
    }

    public function test_dealer_password_is_rejected_on_the_owner_preview(): void
    {
        $dealer = $this->portalUser('dealer');
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'sadmin');

        $this->preview('POST', '/login', [
            'email' => $dealer->email,
            'password' => 'SyntheticPassword123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_preview_login_page_stays_on_http_without_a_portal_hostname(): void
    {
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'dealer');

        $response = $this->preview('GET', '/login');

        $response->assertOk()
            ->assertSee('Account Login')
            ->assertSee('http://127.0.0.1:8088/login', false)
            ->assertDontSee('dealer.localhost')
            ->assertDontSee('upgrade-insecure-requests');
        $csp = (string) $response->headers->get('Content-Security-Policy');
        $this->assertStringContainsString('form-action *', $csp);
        $this->assertStringNotContainsString('upgrade-insecure-requests', $csp);
    }

    public function test_preview_locale_switch_stays_on_the_browser_origin(): void
    {
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'sadmin');

        $this->preview('POST', '/locale', [
            'locale' => 'bn',
            'return_url' => 'http://127.0.0.1:8088/login',
        ])->assertRedirect('http://127.0.0.1:8088/login');
    }

    public function test_portal_cookie_does_not_change_a_real_portal_host(): void
    {
        $this->onPortal('sadmin')
            ->withUnencryptedCookie(PortalHost::PreviewCookie, 'dealer')
            ->get('/login')
            ->assertOk()
            ->assertSee('Owner Sign In');
    }

    public function test_public_preview_path_clears_the_cookie_and_keeps_the_page(): void
    {
        $dealer = $this->portalUser('dealer');
        $this->actingAs($dealer);
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'dealer');

        $this->preview('GET', '/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('portal', 'dealer')
                ->where('publicBase', 'http://127.0.0.1:8088/preview-portal/public'));

        $this->preview('GET', '/preview-portal/public/products')
            ->assertRedirect('/products')
            ->assertCookieExpired(PortalHost::PreviewCookie);
    }

    public function test_public_preview_clears_the_portal_cookie(): void
    {
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'dealer');

        $this->preview('GET', '/preview-portal/public?next=/products')
            ->assertRedirect('/products')
            ->assertCookieExpired(PortalHost::PreviewCookie);
    }

    public function test_preview_switch_rejects_an_external_next_url(): void
    {
        $this->preview('GET', '/preview-portal/dealer?next=//evil.test')
            ->assertRedirect('/login')
            ->assertPlainCookie(PortalHost::PreviewCookie, 'dealer');

        $this->preview('GET', '/preview-portal/manage?next=https://evil.test/login')
            ->assertRedirect('/login');
    }

    public function test_changing_preview_portal_ends_the_previous_session(): void
    {
        $owner = $this->portalUser('super_admin');
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'sadmin');
        $this->preview('POST', '/login', [
            'email' => $owner->email,
            'password' => 'SyntheticPassword123',
        ])->assertRedirect('/');
        $this->assertAuthenticatedAs($owner);

        $this->preview('GET', '/preview-portal/dealer')->assertRedirect('/login');

        $this->assertGuest();
    }

    public function test_repeating_the_same_preview_portal_keeps_the_session(): void
    {
        $owner = $this->portalUser('super_admin');
        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'sadmin');
        $this->preview('POST', '/login', [
            'email' => $owner->email,
            'password' => 'SyntheticPassword123',
        ]);

        $this->preview('GET', '/preview-portal/sadmin?next='.rawurlencode('/'))->assertRedirect('/');

        $this->assertAuthenticatedAs($owner);
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function portalSignIns(): array
    {
        return [
            'owner' => ['sadmin', 'super_admin', 'Owner Sign In'],
            'staff' => ['manage', 'editor', 'Staff Sign In'],
            'dealer' => ['dealer', 'dealer', 'Account Login'],
            'sub-dealer' => ['subdealer', 'sub_dealer', 'Sub-dealer Sign In'],
        ];
    }

    private function portalUser(string $role): User
    {
        if ($role === 'dealer') {
            return User::factory()->dealer()->create(['password' => 'SyntheticPassword123']);
        }
        if ($role === 'sub_dealer') {
            return User::factory()->subDealer()->create(['password' => 'SyntheticPassword123']);
        }

        return User::factory()->staff($role)->create(['password' => 'SyntheticPassword123']);
    }

    /**
     * @param  array<string, mixed>  $parameters
     */
    private function preview(string $method, string $uri, array $parameters = []): TestResponse
    {
        $server = $this->transformHeadersToServerVars(['Host' => '127.0.0.1:8088']);

        return $this->call($method, $uri, $parameters, $this->prepareCookiesForRequest(), [], $server);
    }
}
