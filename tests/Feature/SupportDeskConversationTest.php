<?php

namespace Tests\Feature;

use App\Domain\Support\SupportTickets;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SupportDeskConversationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_the_desk_shows_the_whole_conversation_in_order(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active', 'name' => 'Desk Person']);
        $tickets = app(SupportTickets::class);
        $ticket = $tickets->create($partner, ['category' => 'technical', 'subject' => 'My question', 'body' => 'First message']);

        $tickets->reply($staff, $ticket['id'], 'We are looking at it');
        $tickets->reply($partner, $ticket['id'], 'Here is my order number');

        $card = collect($tickets->adminIndex()['tickets'])->firstWhere('id', $ticket['id']);

        $this->assertSame(['We are looking at it', 'Here is my order number'], array_column($card['replies'], 'body'));
        $this->assertSame([true, false], array_column($card['replies'], 'is_staff'));
        $this->assertSame('Desk Person', $card['replies'][0]['author_name']);
    }

    public function test_the_partner_gets_a_notice_in_their_own_language_when_the_office_answers(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active', 'locale' => 'bn']);
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active', 'locale' => 'en']);
        $tickets = app(SupportTickets::class);
        $ticket = $tickets->create($partner, ['category' => 'technical', 'subject' => 'My question', 'body' => 'First message']);

        $tickets->reply($staff, $ticket['id'], 'We are looking at it');

        $notice = DB::table('account_notices')->where('user_id', $partner->id)->sole();
        $this->assertSame(__('event_emails.support_reply_title', [], 'bn'), $notice->title);
        $this->assertSame(__('event_emails.support_reply_body', ['subject' => 'My question'], 'bn'), $notice->body);
    }

    public function test_a_partner_answering_their_own_request_gets_no_notice(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active']);
        $tickets = app(SupportTickets::class);
        $ticket = $tickets->create($partner, ['category' => 'technical', 'subject' => 'My question', 'body' => 'First message']);

        $tickets->reply($partner, $ticket['id'], 'One more thing');

        $this->assertDatabaseMissing('account_notices', ['user_id' => $partner->id]);
    }

    public function test_the_desk_and_the_partner_can_open_the_attached_file(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active']);
        $id = $this->ticketWithFile($partner);

        $desk = $this->actingAs($staff)->onPortal('manage')->get("/commands/support-tickets/{$id}/attachment");
        $desk->assertOk();
        $desk->assertHeader('X-Content-Type-Options', 'nosniff');
        $desk->assertHeader('Content-Type', 'application/pdf');

        $this->actingAs($partner)->onPortal('subdealer')->get("/commands/support-tickets/{$id}/attachment")->assertOk();
    }

    public function test_nobody_else_can_open_the_attached_file(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active']);
        $otherPartner = User::factory()->subDealer()->create(['status' => 'active']);
        $staffWithoutDesk = User::factory()->create(['role' => 'inventory', 'status' => 'active']);
        $id = $this->ticketWithFile($partner);

        $this->actingAs($otherPartner)->onPortal('subdealer')->get("/commands/support-tickets/{$id}/attachment")->assertNotFound();
        $this->actingAs($staffWithoutDesk)->onPortal('manage')->get("/commands/support-tickets/{$id}/attachment")->assertNotFound();
    }

    public function test_a_request_with_no_file_answers_not_found(): void
    {
        $partner = User::factory()->subDealer()->create(['status' => 'active']);
        $staff = User::factory()->create(['role' => 'support', 'status' => 'active']);
        $ticket = app(SupportTickets::class)->create($partner, ['category' => 'technical', 'subject' => 'No file', 'body' => 'Text only']);

        $this->actingAs($staff)->onPortal('manage')->get("/commands/support-tickets/{$ticket['id']}/attachment")->assertNotFound();
    }

    private function ticketWithFile(User $partner): string
    {
        $file = UploadedFile::fake()->create('proof.pdf', 12, 'application/pdf');

        return app(SupportTickets::class)->create($partner, ['category' => 'technical', 'subject' => 'With file', 'body' => 'See the file'], $file)['id'];
    }
}
