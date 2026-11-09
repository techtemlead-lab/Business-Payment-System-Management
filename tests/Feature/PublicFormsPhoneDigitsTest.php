<?php

namespace Tests\Feature;

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
        ])->assertRedirect('/contact')->assertSessionHasNoErrors();

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
        ])->assertRedirect('/partnership')->assertSessionHasNoErrors();
    }

    public function test_password_messages_exist_in_bangla(): void
    {
        foreach (['reset', 'sent', 'throttled', 'token', 'user'] as $message) {
            $this->assertNotSame(__('passwords.'.$message, [], 'en'), __('passwords.'.$message, [], 'bn'));
        }
    }
}
