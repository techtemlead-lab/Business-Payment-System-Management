<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A public form sits far down its page. After sending it, the visitor must land on the
 * message, or it looks as if nothing happened.
 */
class PublicFormResultTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, string> */
    private function application(): array
    {
        return ['name' => 'Test Applicant', 'email' => 'applicant@example.test', 'phone' => '01811112223', 'date_of_birth' => '1990-01-15', 'role' => 'dealer', 'division' => 'Rangpur', 'district' => 'Rangpur'];
    }

    public function test_a_sent_partnership_form_returns_to_its_message(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application())
            ->assertRedirect('/partnership#partner-apply-status')->assertSessionHasNoErrors();
    }

    public function test_a_wrong_phone_number_on_the_partnership_form_returns_to_the_error(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['phone' => '12345'] + $this->application())
            ->assertRedirect('/partnership#partner-apply-status')->assertSessionHasErrors('phone');
    }

    public function test_a_missing_field_on_the_partnership_form_returns_to_the_error(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['name' => ''] + $this->application())
            ->assertRedirect('/partnership#partner-apply-status')->assertSessionHasErrors('name');
    }

    public function test_an_email_already_applied_returns_to_the_error(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application())->assertSessionHasNoErrors();

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application())
            ->assertRedirect('/partnership#partner-apply-status')->assertSessionHasErrors('email');
    }

    public function test_a_taken_number_is_warned_about_first_without_saying_whose(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
        User::factory()->dealer()->create(['name' => 'Existing Holder', 'phone' => '01811112223']);

        foreach (['dealer', 'sub_dealer'] as $role) {
            $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['role' => $role] + $this->application())
                ->assertRedirect('/partnership#partner-apply-status')
                ->assertSessionHasErrors(['phone_taken' => __('portal.err_public_app_phone_taken')]);
        }

        $this->onPortal('public')->from('/partnership')->followingRedirects()->post('/partnership/apply', $this->application())
            ->assertOk()
            ->assertSee(__('portal.err_public_app_phone_taken'))
            ->assertSee('name="phone_taken_confirmed" value="01811112223"', false)
            ->assertDontSee('Existing Holder');

        $this->assertSame(0, DB::table('dealer_applications')->count());
        $this->assertDatabaseMissing('audit_events', ['action' => 'dealer_application.public_submitted']);
        $this->assertSame([], config('madina.sms.sent'));
    }

    public function test_the_confirm_box_appears_only_after_the_warning(): void
    {
        $this->onPortal('public')->get('/partnership')->assertOk()->assertDontSee('phone_taken_confirmed');
    }

    public function test_the_applicant_can_still_send_a_taken_number_by_confirming_it(): void
    {
        User::factory()->dealer()->create(['phone' => '01811112223']);

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['phone_taken_confirmed' => '01811112223'] + $this->application())
            ->assertRedirect('/partnership#partner-apply-status')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', __('portal.flash_partnership_received'));

        $application = DB::table('dealer_applications')->sole();
        $this->assertSame('pending', $application->status);
        $this->assertSame('01811112223', json_decode($application->data, true, 512, JSON_THROW_ON_ERROR)['phone']);
        $this->assertArrayNotHasKey('phone_taken_confirmed', json_decode($application->data, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_confirming_one_number_does_not_pass_a_different_taken_number(): void
    {
        User::factory()->dealer()->create(['phone' => '01811112223']);

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['phone_taken_confirmed' => '01811112299'] + $this->application())
            ->assertSessionHasErrors('phone_taken');

        $this->assertSame(0, DB::table('dealer_applications')->count());
    }

    public function test_the_taken_number_warning_reads_in_each_language_and_ignores_number_formats(): void
    {
        User::factory()->dealer()->create(['phone' => '01811112223']);
        $this->assertNotSame(__('portal.err_public_app_phone_taken', [], 'en'), __('portal.err_public_app_phone_taken', [], 'bn'));

        foreach (['en', 'bn'] as $locale) {
            $this->onPortal('public')->from('/partnership')->withSession(['locale' => $locale])
                ->post('/partnership/apply', ['phone' => '+880 1811-112223'] + $this->application())
                ->assertSessionHasErrors(['phone_taken' => __('portal.err_public_app_phone_taken', [], $locale)]);
        }
    }

    public function test_the_taken_number_warning_and_its_confirmation_stay_under_the_form_rate_limit(): void
    {
        User::factory()->dealer()->create(['phone' => '01811112223']);

        foreach (range(1, 5) as $attempt) {
            $this->onPortal('public')->from('/partnership')->post('/partnership/apply', $this->application())->assertRedirect();
        }

        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['phone_taken_confirmed' => '01811112223'] + $this->application())->assertTooManyRequests();
        $this->assertSame(0, DB::table('dealer_applications')->count());
    }

    public function test_the_partnership_page_has_the_place_the_address_points_to(): void
    {
        $this->onPortal('public')->get('/partnership')->assertOk()->assertSee('id="partner-apply-status"', false);
    }

    public function test_the_contact_form_returns_to_its_message_and_keeps_the_address(): void
    {
        $enquiry = ['name' => 'Test Visitor', 'phone' => '01811112222', 'topic' => 'support', 'message' => 'A long enough test message.'];

        $this->onPortal('public')->from('/contact?topic=support')->post('/enquiries', $enquiry)
            ->assertRedirect('/contact?topic=support#enquiry-status')->assertSessionHasNoErrors();
        $this->onPortal('public')->from('/contact')->post('/enquiries', ['phone' => 'abc'] + $enquiry)
            ->assertRedirect('/contact#enquiry-status')->assertSessionHasErrors('phone');
        $this->onPortal('public')->from('/contact')->post('/enquiries', ['message' => 'short'] + $enquiry)
            ->assertRedirect('/contact#enquiry-status')->assertSessionHasErrors('message');
    }

    public function test_the_contact_page_has_the_place_the_address_points_to(): void
    {
        $this->onPortal('public')->get('/contact')->assertOk()->assertSee('id="enquiry-status"', false);
    }
}
