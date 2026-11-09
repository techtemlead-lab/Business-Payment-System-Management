<?php

namespace Tests\Feature;

use App\Domain\Accounts\AccountPhoneRules;
use App\Support\PhoneIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Many visitors type on a Bangla keyboard, so a phone number in Bangla digits must work everywhere.
 */
class PublicFormsPhoneDigitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_bangla_digit_number_is_the_same_number(): void
    {
        $this->assertSame('8801811112222', PhoneIdentity::normalize('০১৮১১১১২২২২'));
        $this->assertSame(PhoneIdentity::normalize('01811112222'), PhoneIdentity::normalize('০১৮১১-১১২২২২'));
    }

    public function test_the_contact_form_stores_the_number_in_plain_digits(): void
    {
        $this->onPortal('public')->from('/contact')->post('/enquiries', [
            'name' => 'Test Visitor',
            'phone' => '০১৮১১১১২২২২',
            'topic' => 'support',
            'message' => 'A long enough test message.',
        ])->assertRedirect('/contact#enquiry-status')->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enquiries', ['phone' => '01811112222']);
    }

    public function test_a_partner_can_apply_with_a_bangla_digit_number(): void
    {
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', [
            'name' => 'Test Applicant',
            'email' => 'applicant@example.test',
            'phone' => '০১৮১১১১২২২৩',
            'date_of_birth' => '1990-01-15',
            'role' => 'dealer',
            'division' => 'Rangpur',
            'district' => 'Rangpur',
        ])->assertRedirect('/partnership#partner-apply-status')->assertSessionHasNoErrors();
    }

    public function test_a_number_nobody_could_call_back_is_refused_on_every_public_form(): void
    {
        $this->onPortal('public')->from('/contact')->post('/enquiries', ['name' => 'Test', 'phone' => 'abc', 'topic' => 'support', 'message' => 'A long enough test message.'])
            ->assertRedirect('/contact#enquiry-status')->assertSessionHasErrors('phone');
        $this->onPortal('public')->from('/grievance')->post('/grievance', ['name' => 'Test', 'phone' => '12345', 'topic' => 'complaint', 'message' => 'A long enough test message.'])
            ->assertRedirect('/grievance')->assertSessionHasErrors('phone');
        $this->onPortal('public')->from('/partnership')->post('/partnership/apply', ['name' => 'Test', 'email' => 'a@example.test', 'phone' => '12345', 'date_of_birth' => '1990-01-15', 'role' => 'dealer', 'division' => 'Rangpur', 'district' => 'Rangpur'])
            ->assertRedirect('/partnership#partner-apply-status')->assertSessionHasErrors('phone');

        $this->assertDatabaseCount('enquiries', 0);
    }

    public function test_the_shared_phone_check_reads_bangla_digits(): void
    {
        $this->assertSame('01811112222', app(AccountPhoneRules::class)->normalize('০১৮১১১১২২২২'));
    }

    public function test_password_messages_exist_in_bangla(): void
    {
        foreach (['reset', 'sent', 'throttled', 'token', 'user'] as $message) {
            $this->assertNotSame(__('passwords.'.$message, [], 'en'), __('passwords.'.$message, [], 'bn'));
        }
    }
}
