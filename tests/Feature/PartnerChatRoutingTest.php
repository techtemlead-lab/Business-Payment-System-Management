<?php

namespace Tests\Feature;

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\BranchStaffAssignment;
use App\Models\CompanyBranch;
use App\Models\PartnerMessage;
use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Owner decision: a partner's chat goes to the owners and to the staff the owner nominated for
 * the partner's branch. Staff nominated company-wide see every chat.
 */
class PartnerChatRoutingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private CompanyBranch $north;

    private CompanyBranch $south;

    private User $northDealer;

    private User $southDealer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->owner = User::factory()->staff('md')->create();
        $this->north = CompanyBranch::factory()->create(['district' => 'Panchagarh']);
        $this->south = CompanyBranch::factory()->create(['district' => 'Satkhira']);
        $this->northDealer = User::factory()->dealer()->create(['status' => 'active', 'active_branch_id' => $this->north->id]);
        $this->southDealer = User::factory()->dealer()->create(['status' => 'active', 'active_branch_id' => $this->south->id]);
        $this->actingAs($this->northDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'From the north'])->assertRedirect();
        $this->actingAs($this->southDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'From the south'])->assertRedirect();
    }

    /** @return array<string, mixed> */
    private function page(User $user, string $portal, string $path): array
    {
        $version = (string) (new HandleInertiaRequests)->version(Request::create('/'));

        return $this->actingAs($user)->onPortal($portal)
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => $version])
            ->get($path)->assertOk()->json('props');
    }

    private function staffIn(CompanyBranch $branch): User
    {
        $staff = User::factory()->staff('employee')->create(['profile' => ['permissions' => ['partner_chat']]]);
        BranchStaffAssignment::query()->create(['branch_id' => $branch->id, 'user_id' => $staff->id, 'duty' => 'manager']);

        return $staff;
    }

    /** @param array<int, string> $scopes */
    private function nominate(array $scopes): void
    {
        $version = (int) DB::table('business_settings')->where('key', 'chat_answerers')->value('version');
        $answerers = [];
        foreach ($scopes as $userId => $scope) {
            $answerers[] = ['user_id' => $userId, 'scope' => $scope];
        }
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/chat-answerers', ['answerers' => $answerers, 'version' => $version])
            ->assertRedirect()->assertSessionHasNoErrors();
    }

    /** @return list<int> */
    private function inboxPartnerIds(User $viewer, string $portal = 'manage'): array
    {
        return array_column($this->page($viewer, $portal, '/partner-messages')['data']['chat_inbox']['data'], 'partner_id');
    }

    public function test_staff_who_are_not_nominated_cannot_answer_even_inside_the_branch(): void
    {
        $northStaff = $this->staffIn($this->north);

        $this->actingAs($northStaff)->onPortal('manage')->get('/partner-messages')->assertForbidden();
        $this->actingAs($northStaff)->onPortal('manage')->post('/commands/partner-messages/'.$this->northDealer->id, ['body' => 'Hi'])->assertForbidden();
        $this->assertFalse($this->page($northStaff, 'manage', '/')['auth']['chatAnswerer']);
    }

    public function test_branch_answerers_see_and_answer_only_their_own_branch(): void
    {
        $northStaff = $this->staffIn($this->north);
        $this->nominate([$northStaff->id => 'branch']);

        $this->assertSame([$this->northDealer->id], $this->inboxPartnerIds($northStaff));
        $this->assertTrue($this->page($northStaff, 'manage', '/')['auth']['chatAnswerer']);
        $this->assertSame(1, $this->page($northStaff, 'manage', '/')['navAttention']['partner-messages']);

        $this->actingAs($northStaff)->onPortal('manage')->post('/commands/partner-messages/'.$this->northDealer->id, ['body' => 'We are on it'])
            ->assertRedirect('/partner-messages?with='.$this->northDealer->id);
        $this->actingAs($northStaff)->onPortal('manage')->post('/commands/partner-messages/'.$this->southDealer->id, ['body' => 'Not mine'])
            ->assertForbidden();
        $this->actingAs($northStaff)->onPortal('manage')->get('/partner-messages?with='.$this->southDealer->id)->assertForbidden();
        $this->assertSame(1, PartnerMessage::query()->where('sender_side', 'office')->count());
    }

    public function test_nominated_staff_outside_the_branch_do_not_see_it_unless_company_wide(): void
    {
        $southStaff = $this->staffIn($this->south);
        $floating = User::factory()->staff('employee')->create();
        $companyWide = User::factory()->staff('employee')->create();
        $this->nominate([$southStaff->id => 'branch', $floating->id => 'branch', $companyWide->id => 'company']);

        $this->assertSame([$this->southDealer->id], $this->inboxPartnerIds($southStaff));
        $this->assertSame([], $this->inboxPartnerIds($floating), 'A branch answerer with no branch sees no chats.');
        $this->assertEqualsCanonicalizing([$this->northDealer->id, $this->southDealer->id], $this->inboxPartnerIds($companyWide));
        $this->assertEqualsCanonicalizing([$this->northDealer->id, $this->southDealer->id], $this->inboxPartnerIds($this->owner, 'sadmin'));
        $this->assertSame(2, $this->page($this->owner, 'sadmin', '/')['navAttention']['partner-messages']);
        $this->assertSame(0, $this->page($floating, 'manage', '/')['navAttention']['partner-messages']);

        $this->actingAs($companyWide)->onPortal('manage')->post('/commands/partner-messages/'.$this->northDealer->id, ['body' => 'Head office here'])->assertRedirect();
    }

    public function test_a_partner_without_a_saved_branch_follows_the_nearest_branch_above_their_place_code(): void
    {
        $district = PlaceCode::factory()->district(PlaceCode::factory()->division()->create())->create();
        $upazila = PlaceCode::factory()->upazila($district)->create();
        $this->north->forceFill(['place_code_id' => $district->id])->save();
        $placedDealer = User::factory()->dealer()->inPlace($upazila)->create(['status' => 'active', 'active_branch_id' => null]);
        $this->actingAs($placedDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'No branch saved'])->assertRedirect();
        $northStaff = $this->staffIn($this->north);
        $southStaff = $this->staffIn($this->south);
        $this->nominate([$northStaff->id => 'branch', $southStaff->id => 'branch']);

        $this->assertContains($placedDealer->id, $this->inboxPartnerIds($northStaff));
        $this->assertNotContains($placedDealer->id, $this->inboxPartnerIds($southStaff));
    }

    public function test_a_matching_district_name_no_longer_routes_a_chat(): void
    {
        $namedDealer = User::factory()->dealer()->create(['status' => 'active', 'active_branch_id' => null, 'district' => 'Panchagarh']);
        $this->actingAs($namedDealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Only a district name'])->assertRedirect();
        $northStaff = $this->staffIn($this->north);
        $this->nominate([$northStaff->id => 'branch']);

        $this->assertNotContains($namedDealer->id, $this->inboxPartnerIds($northStaff));
        $this->assertContains($namedDealer->id, $this->inboxPartnerIds($this->owner, 'sadmin'));
    }

    public function test_chat_photos_follow_the_same_routing(): void
    {
        $this->actingAs($this->southDealer)->onPortal('dealer')->post('/commands/messages', ['photo' => UploadedFile::fake()->image('box.png', 200, 200)])->assertRedirect();
        $message = PartnerMessage::query()->whereNotNull('attachment_path')->firstOrFail();
        $northStaff = $this->staffIn($this->north);
        $southStaff = $this->staffIn($this->south);
        $this->nominate([$northStaff->id => 'branch', $southStaff->id => 'branch']);

        $this->actingAs($southStaff)->onPortal('manage')->get('/commands/messages/'.$message->id.'/photo')->assertOk();
        $this->actingAs($northStaff)->onPortal('manage')->get('/commands/messages/'.$message->id.'/photo')->assertNotFound();
    }

    public function test_only_owners_choose_who_answers_and_each_change_is_audited(): void
    {
        $northStaff = $this->staffIn($this->north);
        $this->nominate([$northStaff->id => 'company']);

        $this->actingAs($northStaff)->onPortal('manage')
            ->post('/commands/chat-answerers', ['answerers' => [['user_id' => $northStaff->id, 'scope' => 'company']], 'version' => 1])
            ->assertForbidden();
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/chat-answerers', ['answerers' => [['user_id' => $this->northDealer->id, 'scope' => 'company']], 'version' => 1])
            ->assertSessionHasErrors('answerers');
        $this->actingAs($this->owner)->onPortal('sadmin')
            ->post('/commands/chat-answerers', ['answerers' => [], 'version' => 0])
            ->assertSessionHasErrors('answerers');

        $this->assertDatabaseHas('audit_events', ['action' => 'partner_chat.answerers_changed', 'actor_id' => $this->owner->id]);
        $panel = $this->page($this->owner, 'sadmin', '/partner-messages')['data']['chat_routing'];
        $this->assertSame(1, $panel['version']);
        $this->assertSame([$northStaff->id], array_column($panel['answerers'], 'id'));
        $this->assertNull($this->page($northStaff, 'manage', '/partner-messages')['data']['chat_routing']);
    }
}
