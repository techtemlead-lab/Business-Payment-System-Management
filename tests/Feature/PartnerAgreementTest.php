<?php

namespace Tests\Feature;

use App\Domain\Accounts\PartnerAgreement;
use App\Domain\Staff\LeadershipAccess;
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

    public function test_version_one_is_the_seeded_bilingual_agreement_without_a_draft_note(): void
    {
        $first = PartnerAgreementVersion::query()->sole();

        $this->assertSame(1, $first->version);
        $this->assertStringNotContainsString('খসড়া', $first->body_bn);
        $this->assertStringNotContainsString('আইনজীবী', $first->body_bn);
        $this->assertStringNotContainsStringIgnoringCase('draft', $first->body_en);
        $this->assertStringNotContainsStringIgnoringCase('lawyer', $first->body_en);
        $this->assertStringContainsString('মদিনা ন্যাচারাল হেলথ কেয়ার', $first->body_bn);
        $this->assertStringContainsString('Madina Natural Health Care', $first->body_en);
        $this->assertFalse(app(PartnerAgreement::class)->setting()['required'], 'The switch starts off.');
    }

    public function test_version_one_carries_the_company_details_and_has_no_blank_field_left(): void
    {
        $first = PartnerAgreementVersion::query()->sole();

        foreach (['Registered legal name: Madina Natural Health Care', 'Trade licence number: 2026-00563', 'Registered address: Thana Road, Pirganj 5470, Rangpur, Bangladesh', 'Authorised signatory: Md. Anowar Hossain, Proprietor', 'Legal form of the business: Sole proprietorship'] as $line) {
            $this->assertStringContainsString($line, $first->body_en);
        }
        foreach (['নিবন্ধিত আইনি নাম: মদিনা ন্যাচারাল হেলথ কেয়ার', 'ট্রেড লাইসেন্স নম্বর: ২০২৬-০০৫৬৩', 'নিবন্ধিত ঠিকানা: থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর, বাংলাদেশ'] as $line) {
            $this->assertStringContainsString($line, $first->body_bn);
        }
        $this->assertStringContainsString('the laws of the People’s Republic of Bangladesh', $first->body_en);
        $this->assertStringContainsString('বাংলাদেশের প্রচলিত আইন', $first->body_bn);

        $this->assertStringNotContainsString('[Company to complete:', $first->body_en, 'The agreement is plain and has no blank field left.');
        $this->assertStringNotContainsString('[কোম্পানি পূরণ করবে:', $first->body_bn);
        $this->assertStringNotContainsString('Notice period', $first->body_en);
        $this->assertStringNotContainsString('Court or forum', $first->body_en);
        $this->assertStringNotContainsString('unopened goods', $first->body_en);
    }

    public function test_the_simple_agreement_update_adds_the_plain_text_after_a_version_that_still_has_blanks(): void
    {
        DB::table('partner_agreement_versions')->insert([
            'version' => 2,
            'body_bn' => "মদিনা ন্যাচারাল হেলথ কেয়ার\n• নোটিশের সময়: [কোম্পানি পূরণ করবে: চুক্তি শেষ করার নোটিশের সময়]।",
            'body_en' => "Madina Natural Health Care\n• Notice period: [Company to complete: notice period for ending this agreement].",
            'published_by' => null,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runSimpleAgreementMigration();

        $latest = PartnerAgreementVersion::query()->orderByDesc('version')->first();
        $this->assertSame(3, $latest->version);
        $this->assertNull($latest->published_by);
        $this->assertStringContainsString('Either you or the company can end this agreement.', $latest->body_en);
        $this->assertStringNotContainsString('[Company to complete:', $latest->body_en);
        $this->assertStringContainsString('[Company to complete:', (string) PartnerAgreementVersion::query()->where('version', 2)->value('body_en'), 'The older version stays as it was.');
    }

    public function test_the_simple_agreement_update_leaves_an_owners_own_version_alone(): void
    {
        $owner = User::factory()->staff('md')->create();
        app(PartnerAgreement::class)->publish($owner, 'মালিকের লেখা। [কোম্পানি পূরণ করবে: কিছু]', 'Owner text. [Company to complete: something]');

        $this->runSimpleAgreementMigration();

        $this->assertSame(2, PartnerAgreementVersion::query()->count());
    }

    public function test_the_simple_agreement_update_does_nothing_when_no_blank_is_left(): void
    {
        $this->runSimpleAgreementMigration();

        $this->assertSame(1, PartnerAgreementVersion::query()->count());
    }

    public function test_the_company_details_update_adds_the_filled_text_after_a_blank_one_the_system_published(): void
    {
        DB::table('partner_agreement_versions')->insert([
            'version' => 2,
            'body_bn' => "মদিনা ন্যাচারাল হেলথ কেয়ার\n• নিবন্ধিত আইনি নাম: [কোম্পানি পূরণ করবে: কোম্পানির নিবন্ধিত আইনি নাম]",
            'body_en' => "Madina Natural Health Care\n• Registered legal name: [Company to complete: registered legal name of the company]",
            'published_by' => null,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->runCompanyDetailsMigration();

        $latest = PartnerAgreementVersion::query()->orderByDesc('version')->first();
        $this->assertSame(3, $latest->version);
        $this->assertNull($latest->published_by);
        $this->assertStringContainsString('2026-00563', $latest->body_en);
        $this->assertStringNotContainsString('[Company to complete: registered legal name', $latest->body_en);
        $this->assertStringContainsString('[Company to complete:', (string) PartnerAgreementVersion::query()->where('version', 2)->value('body_en'), 'The blank version stays as it was.');
    }

    public function test_the_company_details_update_leaves_an_owners_own_version_alone(): void
    {
        $owner = User::factory()->staff('md')->create();
        app(PartnerAgreement::class)->publish($owner, 'মালিকের লেখা। [কোম্পানি পূরণ করবে: কোম্পানির নিবন্ধিত আইনি নাম]', 'Owner text. [Company to complete: registered legal name of the company]');

        $this->runCompanyDetailsMigration();

        $this->assertSame(2, PartnerAgreementVersion::query()->count());
        $this->assertSame($owner->id, PartnerAgreementVersion::query()->orderByDesc('version')->first()->published_by);
    }

    public function test_the_company_details_update_does_nothing_on_a_new_install(): void
    {
        $this->runCompanyDetailsMigration();

        $this->assertSame(1, PartnerAgreementVersion::query()->count());
    }

    public function test_owner_publishes_a_new_version_and_the_old_one_stays(): void
    {
        $owner = User::factory()->staff('md')->create();
        // D11 (SA-07): the MD edits the agreement once the Super Admin grants the owner pages.
        LeadershipAccess::save(User::factory()->staff('super_admin')->create(), ['md' => ['owner_tools']], 0);
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

    public function test_each_owner_edit_is_a_new_version_with_who_and_when_and_old_text_stays_viewable(): void
    {
        $owner = User::factory()->staff('super_admin')->create(['name' => 'Owner One']);
        $secondOwner = User::factory()->staff('chairman')->create(['name' => 'Owner Two']);
        $first = PartnerAgreementVersion::query()->sole();

        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(10, 0));
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/agreement/publish', [
            'body_bn' => 'দ্বিতীয় সংস্করণ। মাল কিনবেন শুধু কোম্পানি থেকে।',
            'body_en' => 'Second version. Goods are bought only from the company.',
        ])->assertSessionHasNoErrors();

        $this->travelTo(now()->setDate(2026, 11, 3)->setTime(15, 30));
        $this->actingAs($secondOwner)->onPortal('sadmin')->post('/commands/agreement/publish', [
            'body_bn' => 'তৃতীয় সংস্করণ। সব অর্ডারের টাকা আগে দিতে হবে।',
            'body_en' => 'Third version. Every order is paid in full first.',
        ])->assertSessionHasNoErrors();
        $this->travelBack();

        $versions = PartnerAgreementVersion::query()->orderBy('version')->get();
        $this->assertSame([1, 2, 3], $versions->pluck('version')->all());
        $this->assertSame($first->body_en, $versions[0]->body_en, 'Version 1 text is kept unchanged.');
        $this->assertSame($owner->id, $versions[1]->published_by);
        $this->assertSame('2026-11-01 10:00', $versions[1]->published_at->format('Y-m-d H:i'));
        $this->assertSame($secondOwner->id, $versions[2]->published_by);
        $this->assertSame('2026-11-03 15:30', $versions[2]->published_at->format('Y-m-d H:i'));
        $this->assertFalse(app(PartnerAgreement::class)->setting()['required'], 'Publishing text never turns the must-accept switch on.');

        $this->actingAs($owner)->onPortal('sadmin')->get('/agreement')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.agreement.can_edit', true)
                ->where('data.agreement.setting.required', false)
                ->has('data.agreement.versions', 3)
                ->where('data.agreement.versions.0.published_by', 'Owner Two')
                ->where('data.agreement.versions.0.body_en', 'Third version. Every order is paid in full first.')
                ->where('data.agreement.versions.1.published_by', 'Owner One')
                ->where('data.agreement.versions.1.body_en', 'Second version. Goods are bought only from the company.')
                ->where('data.agreement.versions.2.body_en', $first->body_en)
                ->whereNot('data.agreement.versions.1.published_at', null));
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
        $this->actingAs($dealer)->onPortal('dealer')->get('/messages')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/messages', ['body' => 'Agreement question'])->assertRedirect();
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

    public function test_the_update_replaces_the_seeded_draft_with_the_final_text_as_a_new_version(): void
    {
        $this->storeSeededDraftAsLatest();

        $this->runFinalTextMigration();

        $versions = PartnerAgreementVersion::query()->orderBy('version')->get();
        $this->assertSame([1, 2, 3], $versions->pluck('version')->all());
        $this->assertStringStartsWith('খসড়া', $versions[1]->body_bn, 'The draft version is kept unchanged.');
        $this->assertStringStartsWith('Madina Natural Health Care', $versions[2]->body_en);
        $this->assertStringContainsString('Your dealer code or sub-dealer code given by the company is your refer code.', $versions[2]->body_en);
        $this->assertNull($versions[2]->published_by);

        $this->runFinalTextMigration();
        $this->assertSame(3, PartnerAgreementVersion::query()->count(), 'Running it again adds nothing.');
    }

    public function test_the_update_never_replaces_text_an_owner_saved(): void
    {
        $owner = User::factory()->staff('md')->create();
        app(PartnerAgreement::class)->publish($owner, 'মালিকের নিজের চুক্তি।', 'The owner\'s own agreement.');

        $this->runFinalTextMigration();

        $latest = PartnerAgreementVersion::query()->orderByDesc('version')->first();
        $this->assertSame(2, $latest->version);
        $this->assertSame('মালিকের নিজের চুক্তি।', $latest->body_bn);
        $this->assertSame($owner->id, $latest->published_by);
    }

    public function test_the_update_leaves_a_draft_an_owner_republished_alone(): void
    {
        $owner = User::factory()->staff('md')->create();
        app(PartnerAgreement::class)->publish($owner, "খসড়া — আইনজীবী দিয়ে যাচাই করুন\nমালিকের লেখা।", "Draft — have a lawyer check this\nThe owner's words.");

        $this->runFinalTextMigration();

        $this->assertSame(2, PartnerAgreementVersion::query()->count());
    }

    public function test_the_update_does_nothing_on_a_new_install(): void
    {
        $this->runFinalTextMigration();

        $this->assertSame(1, PartnerAgreementVersion::query()->count());
    }

    private function storeSeededDraftAsLatest(): void
    {
        DB::table('partner_agreement_versions')->insert([
            'version' => 2,
            'body_bn' => "খসড়া — আইনজীবী দিয়ে যাচাই করুন\n\nমদিনা ন্যাচারাল হেলথ কেয়ার",
            'body_en' => "Draft — have a lawyer check this\n\nMadina Natural Health Care",
            'published_by' => null,
            'published_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function runFinalTextMigration(): void
    {
        (require database_path('migrations/2026_11_09_214358_publish_final_partner_agreement_text.php'))->up();
    }

    private function runSimpleAgreementMigration(): void
    {
        (require database_path('migrations/2026_11_17_034152_publish_simple_partner_agreement.php'))->up();
    }

    private function runCompanyDetailsMigration(): void
    {
        (require database_path('migrations/2026_11_17_021457_publish_company_details_in_partner_agreement.php'))->up();
    }

    private function turnSwitchOn(): void
    {
        $owner = User::factory()->staff()->create();
        app(PartnerAgreement::class)->saveSetting($owner, true, 0);
    }

    /** Client report 2026-11-19: a new partner with a first password was locked between the two gates. */
    public function test_a_new_partner_can_change_the_first_password_and_accept_the_agreement_in_either_order(): void
    {
        $owner = User::factory()->staff()->create();
        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/agreement/setting', ['required' => 1, 'version' => 0])->assertSessionHasNoErrors();
        $version = PartnerAgreementVersion::query()->sole();

        // Agreement first, then the password.
        $dealer = User::factory()->dealer()->create(['must_change_password' => true]);
        $this->actingAs($dealer)->onPortal('dealer')->get('/i18n/bn/0000000000000000.json')->assertOk();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/agreement/accept', ['version_id' => $version->id])->assertRedirect('/');
        $this->assertTrue(PartnerAgreementAcceptance::query()->where('user_id', $dealer->id)->exists());
        $this->actingAs($dealer)->onPortal('dealer')->get('/')->assertRedirect('/security');

        // Password first, then the agreement.
        $subDealer = User::factory()->subDealer()->create(['must_change_password' => true]);
        $this->actingAs($subDealer)->onPortal('subdealer')->get('/security')->assertOk();
        $this->actingAs($subDealer)->onPortal('subdealer')->post('/security/password', [
            'current_password' => 'password', 'password' => 'Brand-new-pass-77', 'password_confirmation' => 'Brand-new-pass-77',
        ])->assertSessionHasNoErrors();
        $this->assertFalse($subDealer->fresh()->must_change_password);
        $this->actingAs($subDealer->fresh())->onPortal('subdealer')->get('/')->assertRedirect('/agreement');
        $this->actingAs($subDealer->fresh())->onPortal('subdealer')->post('/commands/agreement/accept', ['version_id' => $version->id])->assertRedirect('/');
        $this->actingAs($subDealer->fresh())->onPortal('subdealer')->get('/')->assertOk();
    }
}
