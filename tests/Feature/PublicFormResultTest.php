<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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
