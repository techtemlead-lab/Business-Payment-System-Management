<?php

namespace Tests\Feature;

use App\Domain\Auth\LoginOtpChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
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

    public function test_bangla_footer_shows_head_office_location_without_currency(): void
    {
        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect();
        $this->onPortal('public')
            ->get('/')
            ->assertOk()
            ->assertSee('পীরগঞ্জ, রংপুর, বাংলাদেশ', false)
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

    public function test_branded_error_pages_render_for_419_and_429_in_both_languages(): void
    {
        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);
            foreach (['419', '429'] as $code) {
                $this->assertNotSame('site.error_'.$code.'_heading', __('site.error_'.$code.'_heading'));
                $this->view('errors.'.$code)
                    ->assertSee($code, false)
                    ->assertSee(__('site.error_'.$code.'_title'), false)
                    ->assertSee(__('site.error_'.$code.'_heading'), false)
                    ->assertSee(__('site.error_'.$code.'_body'), false)
                    ->assertSee(__('site.error_back_home'), false);
            }
        }
        $this->assertNotSame(__('site.error_419_heading', [], 'en'), __('site.error_419_heading', [], 'bn'));
    }

    public function test_too_many_requests_uses_the_branded_429_page(): void
    {
        Route::get('/__throttle-probe', fn () => abort(429));

        $this->onPortal('public')->get('/__throttle-probe')
            ->assertStatus(429)
            ->assertSee(__('site.error_429_heading'), false)
            ->assertSee(__('site.company_bar'), false);
    }

    public function test_login_is_stopped_when_sms_gateway_is_unconfigured_except_for_the_super_admin(): void
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
        $this->assertSame('unavailable', $challenge->decide($dealer));

        $this->onPortal('dealer')
            ->post('/login', ['email' => $dealer->email, 'password' => 'SyntheticPassword123'])
            ->assertSessionHasErrors(['email' => __('auth_ui.otp_sms_unavailable')]);
        $this->assertGuest();
        $this->assertDatabaseHas('audit_events', ['action' => 'login.failed', 'subject' => (string) $dealer->id]);

        // The Super Admin is stopped too (owner decision, 2026-11-05): the owner switch or
        // `madina:sms-login off` on the server turns the code step off when SMS is down.
        $owner = User::factory()->staff('super_admin')->create(['phone' => '01733334445', 'password' => 'SyntheticPassword123']);
        $this->onPortal('sadmin')
            ->post('/login', ['email' => $owner->email, 'password' => 'SyntheticPassword123'])
            ->assertSessionHasErrors(['email' => __('auth_ui.otp_sms_unavailable')]);
        $this->assertGuest();
        $this->assertDatabaseMissing('audit_events', ['action' => 'login.second_factor_bypassed']);
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
        $this->assertSame('Total', __('portal.col_line_total', [], 'en'));
        $this->assertSame('মোট দাম', __('portal.col_line_total', [], 'bn'));
        $this->assertSame('Open Help & Support', __('portal.profile_locked_support_cta', [], 'en'));

        // The live checkout (ProductPurchaseMatrix) shows a total for each line and for the cart.
        $checkout = file_get_contents(resource_path('js/components/ProductPurchaseMatrix.tsx'));
        $this->assertIsString($checkout);
        $this->assertStringContainsString("translate('portal', 'ppm_line_total'", $checkout);
        $this->assertStringContainsString("translate('portal', 'b2b_col_total')", $checkout);
        $this->assertStringContainsString('money(lineTotal)', $checkout);

        $source = file_get_contents(resource_path('js/pages/Portal.tsx'));
        $this->assertIsString($source);
        $this->assertStringContainsString('href={`${base}/help`}', $source);
        $this->assertStringContainsString("translate('portal','profile_locked_support_cta')", $source);
    }
}
