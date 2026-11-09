<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OldNoticeTopicWordsTest extends TestCase
{
    use RefreshDatabase;

    public function test_old_notices_stop_showing_raw_topic_words(): void
    {
        $person = User::factory()->create();
        $oldBody = 'QA Tester (01700000000) sent a website message about partnership_enquiry.';
        $otherBody = 'Your order was approved.';
        $noticeId = DB::table('account_notices')->insertGetId(['user_id' => $person->id, 'title' => 'New message', 'body' => $oldBody, 'created_at' => now(), 'updated_at' => now()]);
        $otherId = DB::table('account_notices')->insertGetId(['user_id' => $person->id, 'title' => 'Order', 'body' => $otherBody, 'created_at' => now(), 'updated_at' => now()]);

        $migration = require database_path('migrations/2026_11_09_070231_replace_raw_topic_words_in_old_notices.php');
        $migration->up();

        $fixed = (string) DB::table('account_notices')->where('id', $noticeId)->value('body');
        $this->assertStringNotContainsString('partnership_enquiry', $fixed);
        $this->assertStringContainsString(__('portal.enquiry_topic_partnership', [], 'en'), $fixed);
        $this->assertSame($otherBody, DB::table('account_notices')->where('id', $otherId)->value('body'));
    }
}
