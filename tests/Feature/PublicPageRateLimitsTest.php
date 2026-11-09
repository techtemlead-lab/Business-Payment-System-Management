<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Every public page and form has its own limit, so browsing the site cannot use up a form's.
 */
class PublicPageRateLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_other_limited_pages_does_not_use_up_a_forms_limit(): void
    {
        foreach (range(1, 6) as $opening) {
            $this->onPortal('public')->get('/batch-check')->assertOk();
        }

        $this->onPortal('public')->from('/grievance')->post('/grievance', $this->complaint())->assertRedirect('/grievance');
    }

    public function test_opening_the_form_page_does_not_use_up_the_forms_limit(): void
    {
        foreach (range(1, 6) as $opening) {
            $this->onPortal('public')->get('/grievance')->assertOk();
        }

        $this->onPortal('public')->from('/grievance')->post('/grievance', $this->complaint())->assertRedirect('/grievance');
    }

    public function test_a_form_still_stops_at_its_own_limit(): void
    {
        foreach (range(1, 5) as $attempt) {
            $this->onPortal('public')->from('/grievance')->post('/grievance', $this->complaint())->assertRedirect('/grievance');
        }

        $this->onPortal('public')->from('/grievance')->post('/grievance', $this->complaint())->assertStatus(429);
    }

    /**
     * @return array{name: string, phone: string, topic: string, message: string}
     */
    private function complaint(): array
    {
        return ['name' => 'Test Visitor', 'phone' => '01811112222', 'topic' => 'complaint', 'message' => 'A long enough complaint message.'];
    }
}
