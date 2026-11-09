<?php

namespace Tests\Feature;

use App\Domain\Support\ChatRouting;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/** Support staff with no branch see every partner chat, and can answer it. */
class SupportChatNoBranchTest extends TestCase
{
    use RefreshDatabase;

    private User $northDealer;

    private User $southDealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $north = CompanyBranch::factory()->create(['district' => 'Panchagarh']);
        $south = CompanyBranch::factory()->create(['district' => 'Satkhira']);
        $this->northDealer = User::factory()->dealer()->create(['status' => 'active', 'active_branch_id' => $north->id]);
        $this->southDealer = User::factory()->dealer()->create(['status' => 'active', 'active_branch_id' => $south->id]);
        $this->actingAs($this->northDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'From the north'])->assertRedirect();
        $this->actingAs($this->southDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'From the south'])->assertRedirect();
    }

    /** @return list<int> */
    private function inbox(User $viewer): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));
        $props = $this->actingAs($viewer)->onPortal('manage')
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get('/partner-messages')->assertOk()->json('props');

        return array_column($props['data']['chat_inbox']['data'], 'partner_id');
    }

    public function test_support_staff_with_no_branch_see_every_chat_and_can_answer(): void
    {
        $support = User::factory()->staff('support')->create();

        $this->assertEqualsCanonicalizing([$this->northDealer->id, $this->southDealer->id], $this->inbox($support));
        $this->assertTrue(app(ChatRouting::class)->canSeePartner($support, $this->southDealer));

        $this->actingAs($support)->onPortal('manage')->post('/commands/partner-messages/'.$this->southDealer->id, ['body' => 'Hello from the office'])->assertRedirect();
    }

    public function test_support_staff_with_a_branch_still_see_only_that_branch(): void
    {
        $support = User::factory()->staff('support')->create();
        $north = CompanyBranch::query()->findOrFail($this->northDealer->active_branch_id);
        BranchStaffAssignment::query()->create(['branch_id' => $north->id, 'user_id' => $support->id, 'duty' => 'manager']);

        $this->assertSame([$this->northDealer->id], $this->inbox($support));
    }

    public function test_a_nominated_staff_member_with_no_branch_still_sees_no_chats(): void
    {
        $owner = User::factory()->staff('md')->create();
        $nominee = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['partner_chat']]]);
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/chat-answerers', ['answerers' => [['user_id' => $nominee->id, 'scope' => 'branch']], 'version' => 0])
            ->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame([], $this->inbox($nominee));
    }
}
