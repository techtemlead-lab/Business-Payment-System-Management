<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PartnerEventSmsTest extends TestCase
{
    use RefreshDatabase;

    public function test_partner_support_logs_event_sms_when_phone_present(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01720000088',
            'dealer_code' => 'MNHC-SMS',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'support',
                'message' => 'Please help update my shop address on file.',
            ])
            ->assertRedirect();

        $sent = config('madina.sms.sent');
        $this->assertIsArray($sent);
        $this->assertNotEmpty($sent);
        $this->assertSame('support.requested', $sent[0]['event'] ?? null);
        $this->assertSame('01720000088', $sent[0]['phone'] ?? null);
    }

    public function test_partner_support_sms_uses_localized_topic_label(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'locale' => 'bn',
            'phone' => '01720000089',
            'dealer_code' => 'MNHC-SMS-BN',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'Order dispute',
                'message' => 'Delivery still shows pending after courier pickup.',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('enquiries', [
            'phone' => '01720000089',
            'topic' => 'order_dispute',
        ]);
        $sent = config('madina.sms.sent');
        $this->assertSame(
            __('sms.support_requested', ['topic' => __('portal.support_topic_order', [], 'bn')], 'bn'),
            $sent[0]['message'] ?? null,
        );
    }
}
