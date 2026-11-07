<?php

namespace Tests\Feature;

use App\Logging\MaskPhoneNumbers;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Partner phone numbers never sit in a log file in full: only the last 3 digits stay. */
class LogPhoneMaskingTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = storage_path('logs/phone-mask-test-'.uniqid().'.log');
        config(['logging.channels.single.path' => $this->path]);
        Log::forgetChannel('single');
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        parent::tearDown();
    }

    public function test_phone_numbers_in_the_message_and_context_are_masked_in_the_log_file(): void
    {
        Log::channel('single')->warning('partner.event.sms.undelivered to 01712345678', [
            'user_id' => 42,
            'phone' => '8801812345678',
            'nested' => ['to' => '+8801912345678', 'note' => 'call 01511223344 please'],
            'amount' => '1500.00',
            'order' => 'MN-261107-ABCD1234',
        ]);

        $line = (string) file_get_contents($this->path);
        foreach (['01712345678', '8801812345678', '01912345678', '01511223344'] as $phone) {
            $this->assertStringNotContainsString($phone, $line);
        }
        $this->assertStringContainsString('********678', $line);
        $this->assertStringContainsString('**********678', $line);
        $this->assertStringContainsString('call ********344 please', $line);
        $this->assertStringContainsString('"user_id":42', $line);
        $this->assertStringContainsString('1500.00', $line);
        $this->assertStringContainsString('MN-261107-ABCD1234', $line);
    }

    public function test_every_file_and_alert_channel_uses_the_mask(): void
    {
        foreach (['single', 'daily', 'monthly', 'slack', 'papertrail', 'stderr', 'syslog', 'errorlog'] as $channel) {
            $this->assertContains(MaskPhoneNumbers::class, (array) config('logging.channels.'.$channel.'.tap'), $channel);
        }
    }

    public function test_numbers_that_are_not_mobile_numbers_are_left_alone(): void
    {
        $this->assertSame('Order 1234567890123 total 01234', MaskPhoneNumbers::mask('Order 1234567890123 total 01234'));
        $this->assertSame('********678', MaskPhoneNumbers::mask('01712345678'));
        $this->assertSame('x 017123456789 y', MaskPhoneNumbers::mask('x 017123456789 y'), 'Twelve digits is not a mobile number.');
    }
}
