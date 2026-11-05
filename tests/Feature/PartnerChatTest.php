<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\PartnerConversation;
use App\Models\PartnerMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartnerChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function page(User $user, string $portal, string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($user)->onPortal($portal)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props');
    }

    public function test_each_partner_has_one_chat_with_the_office(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'আমার অর্ডার কবে পাব?'])->assertRedirect();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'অর্ডার নম্বর MN-1'])->assertRedirect();

        $this->assertSame(1, PartnerConversation::query()->where('partner_id', $dealer->id)->count());
        $conversation = PartnerConversation::query()->where('partner_id', $dealer->id)->firstOrFail();
        $this->assertSame(2, $conversation->office_unread);
        $this->assertSame(2, PartnerMessage::query()->where('conversation_id', $conversation->id)->count());
        $this->assertSame('অর্ডার নম্বর MN-1', $conversation->last_message_preview);
    }

    public function test_partners_cannot_reply_as_the_office_or_message_other_partners(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $other = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/partner-messages/'.$other->id, ['body' => 'hello'])->assertForbidden();
        $this->assertSame(0, PartnerMessage::query()->count());
        $this->actingAs($dealer)->onPortal('dealer')->get('/partner-messages')->assertNotFound();
    }

    public function test_owners_and_chosen_staff_answer_but_other_staff_cannot(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Help please'])->assertRedirect();
        $md = User::factory()->staff('md')->create();
        $chosen = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['partner_chat']]]);
        $support = User::factory()->staff('support')->create();

        $this->actingAs($support)->onPortal('manage')->get('/partner-messages')->assertForbidden();
        $this->actingAs($support)->onPortal('manage')->post('/commands/partner-messages/'.$dealer->id, ['body' => 'Hi'])->assertForbidden();

        $inbox = $this->page($chosen, 'manage', '/partner-messages')['data']['chat_inbox']['data'];
        $this->assertCount(1, $inbox);
        $this->assertSame(1, $inbox[0]['unread']);
        $this->actingAs($chosen)->onPortal('manage')->post('/commands/partner-messages/'.$dealer->id, ['body' => 'We are checking'])->assertRedirect('/partner-messages?with='.$dealer->id);
        $this->actingAs($md)->onPortal('sadmin')->post('/commands/partner-messages/'.$dealer->id, ['body' => 'Your goods leave today'])->assertRedirect();

        $conversation = PartnerConversation::query()->where('partner_id', $dealer->id)->firstOrFail();
        $this->assertSame(0, $conversation->office_unread);
        $this->assertSame(2, $conversation->partner_unread);
    }

    public function test_reading_clears_unread_badges_on_both_sides(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->staff()->create();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Hello office'])->assertRedirect();

        $this->assertSame(1, $this->page($owner, 'sadmin', '/')['navAttention']['partner-messages']);
        $thread = $this->page($owner, 'sadmin', '/partner-messages?with='.$dealer->id)['data']['chat_thread'];
        $this->assertSame('Hello office', $thread['messages'][0]['body']);
        $this->assertSame(0, $this->page($owner, 'sadmin', '/')['navAttention']['partner-messages']);

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/partner-messages/'.$dealer->id, ['body' => 'Hello partner'])->assertRedirect();
        $this->assertSame(1, $this->page($dealer, 'dealer', '/')['navAttention']['messages']);
        $chat = $this->page($dealer, 'dealer', '/messages')['data']['chat'];
        $this->assertSame(['partner', 'office'], array_column($chat['messages'], 'side'));
        $this->assertNull($chat['messages'][1]['sender_name'], 'Partners see "Madina office", not the staff name.');
        $this->assertSame(0, $this->page($dealer, 'dealer', '/')['navAttention']['messages']);
    }

    public function test_photos_are_private_to_the_chat(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $other = User::factory()->dealer()->create(['status' => 'active']);
        $owner = User::factory()->staff()->create();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['photo' => UploadedFile::fake()->image('damaged.png', 300, 300)])->assertRedirect();
        $message = PartnerMessage::query()->firstOrFail();
        Storage::disk('local')->assertExists($message->attachment_path);

        $this->actingAs($dealer)->onPortal('dealer')->get('/commands/messages/'.$message->id.'/photo')->assertOk();
        $this->actingAs($owner)->onPortal('sadmin')->get('/commands/messages/'.$message->id.'/photo')->assertOk();
        $this->actingAs($other)->onPortal('dealer')->get('/commands/messages/'.$message->id.'/photo')->assertNotFound();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['photo' => UploadedFile::fake()->create('virus.exe', 10)])
            ->assertSessionHasErrors('photo');
    }

    public function test_an_empty_message_is_refused(): void
    {
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => '   '])->assertSessionHasErrors('body');
        $this->assertSame(0, PartnerMessage::query()->count());
    }
}
