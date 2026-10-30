<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginOtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AuditRemediationFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_footer_quick_view_image_omits_empty_src(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('id="qvImage"', $html);
        $this->assertDoesNotMatchRegularExpression('/id="qvImage"[^>]*\ssrc=""/', $html);
        $this->assertDoesNotMatchRegularExpression('/id="qvImage"[^>]*\ssrc=\'\'/', $html);
        $this->assertMatchesRegularExpression('/<a[^>]*href="\/products"[^>]*id="qvViewDetails"/', $html);
        $this->assertDoesNotMatchRegularExpression('/<a[^>]*href="#"[^>]*id="qvViewDetails"/', $html);
    }

    public function test_error_layout_logo_declares_stable_dimensions(): void
    {
        $html = (string) $this->view('errors.404');

        $this->assertMatchesRegularExpression(
            '/madina-header-logo\.png[^>]*width="180"[^>]*height="48"/',
            $html,
        );
        $this->assertStringContainsString('max-height:48px', $html);
    }

    public function test_bangla_footer_shows_dhaka_location_without_currency(): void
    {
        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect();
        $this->onPortal('public')
            ->get('/')
            ->assertOk()
            ->assertSee('ঢাকা, বাংলাদেশ', false)
            ->assertDontSee('এশিয়া/ঢাকা · BDT', false)
            ->assertDontSee('এশিয়া/ঢাকা · ৳', false);
    }

    public function test_branded_error_pages_render_for_404_403_and_500(): void
    {
        $this->onPortal('public')
            ->get('/definitely-missing-madina-page-'.uniqid())
            ->assertNotFound()
            ->assertSee(__('site.error_404_heading'), false)
            ->assertSee(__('site.error_back_home'), false)
            ->assertSee(__('site.company_bar'), false);

        $forbidden = $this->view('errors.403');
        $forbidden->assertSee(__('site.error_403_heading'), false)
            ->assertSee(__('site.error_back_home'), false);

        $server = $this->view('errors.500');
        $server->assertSee(__('site.error_500_heading'), false)
            ->assertSee(__('site.error_back_home'), false);
    }

    public function test_login_otp_is_skipped_when_sms_gateway_is_unconfigured(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.login_otp.enabled', true);
        Config::set('madina.sms.driver', 'log');
        Config::set('madina.sms.sent', []);

        $challenge = app(LoginOtpChallenge::class);
        $this->assertFalse($challenge->canDeliver());

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01733334444',
            'password' => 'SyntheticPassword123',
        ]);

        $this->assertFalse($challenge->requiredFor($dealer));

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/');
        $this->assertAuthenticatedAs($dealer);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_login_otp_still_works_with_array_sms_driver_outside_preview(): void
    {
        Config::set('madina.preview_mode', false);
        Config::set('madina.login_otp.enabled', true);
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01744445555',
            'password' => 'SyntheticPassword123',
        ]);

        $this->assertTrue(app(LoginOtpChallenge::class)->requiredFor($dealer));

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertRedirect('/login/otp');
        $this->assertGuest();
        $this->assertNotEmpty(config('madina.sms.sent'));
    }

    public function test_portal_checkout_review_exposes_line_and_order_total_copy(): void
    {
        $this->assertSame('Line total', __('portal.col_line_total', [], 'en'));
        $this->assertSame('লাইন মোট', __('portal.col_line_total', [], 'bn'));
        $this->assertSame('Open Help & Support', __('portal.profile_locked_support_cta', [], 'en'));

        $source = file_get_contents(resource_path('js/pages/Portal.tsx'));
        $this->assertIsString($source);
        $this->assertStringContainsString("translate('portal','col_line_total')", $source);
        $this->assertStringContainsString("translate('portal','order_total')", $source);
        $this->assertStringContainsString('money(cartTotal)', $source);
        $this->assertStringContainsString('href={`${base}/help`}', $source);
        $this->assertStringContainsString("translate('portal','profile_locked_support_cta')", $source);
    }
}
