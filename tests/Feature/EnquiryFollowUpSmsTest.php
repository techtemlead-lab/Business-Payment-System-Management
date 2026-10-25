<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EnquiryFollowUpSmsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);
    }

    public function test_public_enquiry_stores_locale_and_contacted_status_sends_sms(): void
    {
        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect('/');
        $this->onPortal('public')
            ->from('/contact')
            ->post('/enquiries', [
                'name' => 'Follow-up SMS Visitor',
                'phone' => '01720000097',
                'topic' => 'product_enquiry',
                'message' => 'Need product availability details for Rangpur stockists.',
            ])
            ->assertRedirect('/contact');

        $this->assertDatabaseHas('enquiries', [
            'phone' => '01720000097',
            'topic' => 'product_enquiry',
            'locale' => 'bn',
            'status' => 'new',
        ]);
        $id = (int) DB::table('enquiries')->where('phone', '01720000097')->value('id');
        Config::set('madina.sms.sent', []);

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/enquiries/'.$id, ['status' => 'contacted'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $sent = config('madina.sms.sent');
        $this->assertSame('enquiry.contacted', $sent[0]['event'] ?? null);
        $this->assertSame('01720000097', $sent[0]['phone'] ?? null);
        $this->assertSame(
            __('sms.enquiry_follow_up_contacted', [
                'topic' => __('portal.enquiry_topic_product', [], 'bn'),
                'status' => __('portal.enquiry_status_contacted', [], 'bn'),
            ], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }

    public function test_closed_status_sends_sms_once_per_transition(): void
    {
        $id = DB::table('enquiries')->insertGetId([
            'name' => 'Closed SMS Visitor',
            'phone' => '01720000098',
            'topic' => 'support',
            'message' => 'Please close after callback.',
            'status' => 'contacted',
            'locale' => 'en',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $editor = User::factory()->create(['role' => 'editor', 'status' => 'active']);
        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/enquiries/'.$id, ['status' => 'closed'])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertSame('enquiry.closed', $sent[0]['event'] ?? null);
        $this->assertSame(
            __('sms.enquiry_follow_up_closed', [
                'topic' => __('portal.support_topic_support', [], 'en'),
                'status' => __('portal.enquiry_status_closed', [], 'en'),
            ], 'en'),
            $sent[0]['message'] ?? null,
        );

        Config::set('madina.sms.sent', []);
        $this->actingAs($editor)->onPortal('manage')
            ->post('/commands/enquiries/'.$id, ['status' => 'closed'])
            ->assertRedirect();
        $this->assertSame([], config('madina.sms.sent'));
    }
}
