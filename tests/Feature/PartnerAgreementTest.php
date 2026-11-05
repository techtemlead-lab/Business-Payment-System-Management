<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAgreement;
use App\Models\PartnerAgreementAcceptance;
use App\Models\PartnerAgreementVersion;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The on-screen dealer agreement: owners publish versions, partners accept
 * the latest one, and the owner switch decides whether the portal waits.
 */
class PartnerAgreementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_version_one_is_the_seeded_bilingual_draft(): void
    {
        $first = PartnerAgreementVersion::query()->sole();

        $this->assertSame(1, $first->version);
        $this->assertStringStartsWith('খসড়া — আইনজীবী দিয়ে যাচাই করুন', $first->body_bn);
        $this->assertStringStartsWith('Draft — have a lawyer check this', $first->body_en);
        $this->assertStringContainsString('মদিনা ন্যাচারাল হেলথ কেয়ার', $first->body_bn);
        $this->assertStringContainsString('Madina Natural Health Care', $first->body_en);
        $this->assertFalse(app(PartnerAgreement::class)->setting()['required'], 'The switch starts off.');
    }

    public function test_owner_publishes_a_new_version_and_the_old_one_stays(): void
    {
        $owner = User::factory()->staff('md')->create();
        $first = PartnerAgreementVersion::query()->sole();

        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/agreement/publish', [
                'body_bn' => 'নতুন চুক্তি। সব অর্ডারের পুরো টাকা আগে দিতে হবে।',
                'body_en' => 'New agreement. Every order is paid in full first.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $latest = PartnerAgreementVersion::query()->orderByDesc('version')->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame($owner->id, $latest->published_by);
        $this->assertSame('New agreement. Every order is paid in full first.', $latest->body_en);
        $this->assertSame($first->body_en, $first->fresh()->body_en);
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'agreement.published', 'subject' => 'partner_agreement_version:'.$latest->id]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/sadmin/Dashboard')
                ->where('section', 'agreement')
                ->where('data.agreement.mode', 'office')
                ->where('data.agreement.can_edit', true)
                ->has('data.agreement.versions', 2)
                ->where('data.agreement.versions.0.version', 2)
                ->where('data.agreement.versions.0.published_by', $owner->name)
                ->where('data.agreement.versions.1.version', 1));
    }

    public function test_published_versions_cannot_be_edited_or_removed(): void
    {
        $first = PartnerAgreementVersion::query()->sole();

        try {
            DB::table('partner_agreement_versions')->where('id', $first->id)->update(['body_en' => 'Changed']);
            $this->fail('Editing a published version must be refused.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('read-only', $exception->getMessage());
        }
    }

    public function test_staff_and_partners_cannot_publish_or_change_the_switch(): void
    {
        $staff = User::factory()->staff('account_manager')->create();
        $dealer = User::factory()->dealer()->create();
        $text = ['body_bn' => 'অন্য কেউ চুক্তি বদলাতে চাইছে, এটা হবে না।', 'body_en' => 'Someone else is trying to change the agreement.'];

        $this->actingAs($staff)->onPortal('manage')->post('/commands/agreement/publish', $text)->assertForbidden();
        $this->actingAs($staff)->onPortal('manage')->post('/commands/agreement/setting', ['required' => 1, 'version' => 0])->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/agreement/publish', $text)->assertForbidden();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/agreement/setting', ['required' => 1, 'version' => 0])->assertForbidden();

        $this->assertSame(1, PartnerAgreementVersion::query()->count());
        $this->assertFalse(app(PartnerAgreement::class)->setting()['required']);
        $this->assertDatabaseMissing('audit_events', ['action' => 'agreement.published']);
    }

    public function test_partners_are_not_blocked_while_the_switch_is_off(): void
    {
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders')->assertOk();
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.agreement.mode', 'partner')
                ->where('data.agreement.required', false)
                ->where('data.agreement.blocking', false)
                ->where('data.agreement.accepted', false));
    }

    public function test_switch_on_sends_partners_to_the_agreement_until_they_accept(): void
    {
        $owner = User::factory()->staff()->create();
        $dealer = User::factory()->dealer()->create();
        $subDealer = User::factory()->subDealer()->create();
        $this->actingAs($owner)->onPortal('sadmin')
            ->post('/commands/agreement/setting', ['required' => 1, 'version' => 0])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_events', ['actor_id' => $owner->id, 'action' => 'agreement.requirement_changed']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertRedirect('/agreement');
        $this->actingAs($dealer)->onPortal('dealer')->get('/orders')->assertRedirect('/agreement');
        $this->actingAs($dealer)->onPortal('dealer')->get('/full-report/pdf')->assertRedirect('/agreement');
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/withdrawals', ['amount' => '500'])->assertRedirect('/agreement');
        $this->actingAs($dealer)->onPortal('dealer')->getJson('/orders')->assertForbidden();
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/earnings')->assertRedirect('/agreement');

        $first = PartnerAgreementVersion::query()->sole();
        $this->actingAs($dealer)->onPortal('dealer')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/dealer/Dashboard')
                ->where('section', 'agreement')
                ->where('data.agreement.required', true)
                ->where('data.agreement.blocking', true)
                ->where('data.agreement.latest.id', $first->id)
                ->where('data.agreement.latest.body_bn', $first->body_bn)
                ->where('data.agreement.latest.body_en', $first->body_en));
        $this->actingAs($dealer)->onPortal('dealer')->get('/help')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->post('/logout')->assertRedirect();
        $this->assertGuest();

        // Owners and office staff are never held back by the partner agreement.
        $this->actingAs($owner)->onPortal('sadmin')->get('/')->assertOk();
    }

    public function test_acceptance_is_recorded_once_with_ip_and_device(): void
    {
        $this->turnSwitchOn();
        $dealer = User::factory()->dealer()->create();
        $first = PartnerAgreementVersion::query()->sole();

        $this->actingAs($dealer)->onPortal('dealer')
            ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
            ->withHeader('User-Agent', 'Mozilla/5.0 (Linux; Android 13) Madina test phone')
            ->post('/commands/agreement/accept', ['version_id' => $first->id])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/agreement/accept', ['version_id' => $first->id])
            ->assertRedirect('/');

        $acceptance = PartnerAgreementAcceptance::query()->sole();
        $this->assertSame($dealer->id, $acceptance->user_id);
        $this->assertSame($first->id, $acceptance->partner_agreement_version_id);
        $this->assertSame('203.0.113.7', $acceptance->ip_address);
        $this->assertSame('Mozilla/5.0 (Linux; Android 13) Madina test phone', $acceptance->user_agent);
        $this->assertNotNull($acceptance->accepted_at);
        $this->assertSame(1, DB::table('audit_events')->where('action', 'agreement.accepted')->where('actor_id', $dealer->id)->count());

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.agreement.accepted', true)
                ->where('data.agreement.blocking', false)
                ->where('data.agreement.accepted_versions.0.version', 1));
    }

    public function test_a_new_version_needs_acceptance_again(): void
    {
        $this->turnSwitchOn();
        $owner = User::factory()->staff('chairman')->create();
        $dealer = User::factory()->dealer()->create();
        $first = PartnerAgreementVersion::query()->sole();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/agreement/accept', ['version_id' => $first->id])->assertRedirect('/');
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/agreement/publish', [
            'body_bn' => 'দ্বিতীয় সংস্করণ। কমিশনের হার পোর্টালে দেখানো থাকবে।',
            'body_en' => 'Second version. Commission rates are shown in the portal.',
        ])->assertRedirect();
        $second = PartnerAgreementVersion::query()->where('version', 2)->sole();

        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertRedirect('/agreement');
        // A page opened before the new version was published cannot accept the old text.
        $this->actingAs($dealer)->onPortal('dealer')->from('/agreement')
            ->post('/commands/agreement/accept', ['version_id' => $first->id])
            ->assertRedirect('/agreement')
            ->assertSessionHasErrors('version');
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertRedirect('/agreement');

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/agreement/accept', ['version_id' => $second->id])->assertRedirect('/');
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertOk();
        $this->assertSame([2, 1], PartnerAgreementAcceptance::query()
            ->where('user_id', $dealer->id)
            ->join('partner_agreement_versions', 'partner_agreement_versions.id', '=', 'partner_agreement_acceptances.partner_agreement_version_id')
            ->orderByDesc('partner_agreement_versions.version')
            ->pluck('partner_agreement_versions.version')
            ->map(fn ($version): int => (int) $version)
            ->all());
    }

    public function test_staff_with_accounts_permission_see_who_accepted(): void
    {
        $accountsStaff = User::factory()->staff('account_manager')->create();
        $supportStaff = User::factory()->staff('support')->create();
        $accepted = User::factory()->dealer()->create(['name' => 'Abdul Karim']);
        User::factory()->subDealer()->create(['name' => 'Rahima Begum']);
        $first = PartnerAgreementVersion::query()->sole();
        app(PartnerAgreement::class)->accept($accepted, $first->id, '198.51.100.4', 'Test browser');

        $this->actingAs($accountsStaff)->onPortal('manage')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('portals/manage/Dashboard')
                ->where('data.agreement.mode', 'office')
                ->where('data.agreement.can_edit', false)
                ->where('data.agreement.summary.accepted_latest', 1)
                ->where('data.agreement.summary.partners', 2)
                ->where('data.agreement.partners.data.0.name', 'Abdul Karim')
                ->where('data.agreement.partners.data.0.accepted_version', 1)
                ->where('data.agreement.partners.data.0.ip_address', '198.51.100.4')
                ->where('data.agreement.partners.data.1.name', 'Rahima Begum')
                ->where('data.agreement.partners.data.1.accepted_version', null));

        $this->actingAs($accountsStaff)->onPortal('manage')->get('/agreement?q=Rahima')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('data.agreement.partners.data', 1));

        $this->actingAs($supportStaff)->onPortal('manage')->get('/agreement')->assertForbidden();
    }

    public function test_the_partner_page_check_is_one_query(): void
    {
        $this->turnSwitchOn();
        $dealer = User::factory()->dealer()->create();
        $agreement = app(PartnerAgreement::class);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $status = $agreement->status($dealer);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries, 'Switch, latest version and acceptance should come from one query.');
        $this->assertSame(['required' => true, 'version_id' => PartnerAgreementVersion::query()->sole()->id, 'version' => 1, 'accepted' => false], $status);
        $this->assertTrue($agreement->blocks($dealer, $status));
        $this->assertFalse($agreement->blocks(User::factory()->staff()->create(), $status), 'Owners are never blocked.');
    }

    private function turnSwitchOn(): void
    {
        $owner = User::factory()->staff()->create();
        app(PartnerAgreement::class)->saveSetting($owner, true, 0);
    }
}
