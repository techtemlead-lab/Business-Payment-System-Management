<?php

namespace Tests\Feature;

use App\Domain\Support\SupportTickets;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerMoneySupportHubTest extends TestCase
{
    use RefreshDatabase;

    private function dealer(array $attributes = []): User
    {
        return User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'phone' => '01710000101',
            'dealer_code' => 'MNHC-HUB1',
            ...$attributes,
        ]);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noticeRoutes(): array
    {
        return [
            'office notices' => ['/circulars', 'circulars'],
            'personal updates' => ['/notifications', 'notifications'],
        ];
    }

    #[DataProvider('noticeRoutes')]
    public function test_notices_and_personal_updates_share_one_place(string $path, string $tab): void
    {
        $this->actingAs($this->dealer())->onPortal('dealer')
            ->get($path)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', $tab)
                ->where('data.partner_hub.active', $tab)
                ->where('data.partner_hub.tabs', fn ($tabs) => collect($tabs)->pluck('path')->all() === ['/circulars', '/notifications']));
    }

    public function test_office_pages_do_not_get_partner_tabs(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/notifications')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('section', 'notifications')->missing('data.partner_hub'));
    }

    public function test_requests_from_both_old_forms_still_show_to_the_partner(): void
    {
        $dealer = $this->dealer();

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', [
                'topic' => 'kyc_correction',
                'message' => 'Please correct the spelling of my father name on the NID record.',
            ])
            ->assertRedirect();
        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/support-tickets', [
                'category' => 'commission',
                'subject' => 'March commission',
                'body' => 'My March commission looks short.',
            ])
            ->assertRedirect();

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('section', 'help')
                ->has('data.support_tickets', 1)
                ->where('data.support_tickets.0.subject', 'March commission')
                ->where('data.support_tickets.0.body', 'My March commission looks short.')
                ->has('data.earlier_support_requests', 1)
                ->where('data.earlier_support_requests.0.body', 'Please correct the spelling of my father name on the NID record.')
                ->where('data.earlier_support_requests.0.status', 'new')
                ->where('data.earlier_support_requests.0.earlier', true));
    }

    public function test_old_requests_stay_on_both_office_desks(): void
    {
        $dealer = $this->dealer();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/partner-support', [
            'topic' => 'complaint',
            'message' => 'The courier left the parcel with the wrong shop.',
        ]);
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/support-tickets', [
            'category' => 'technical',
            'subject' => 'Cannot print',
            'body' => 'The invoice page will not print for me.',
        ]);
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);

        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/enquiries')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('data.enquiries.data.0.topic', 'complaint')
                ->where('data.enquiries.data.0.message', '[MNHC-HUB1] The courier left the parcel with the wrong shop.'));
        $this->actingAs($owner)->onPortal('sadmin')
            ->get('/support-admin')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('data.support_admin.table.rows.data.0.subject', 'Cannot print'));
    }

    public function test_office_replies_reach_the_partner_without_staff_names(): void
    {
        $dealer = $this->dealer();
        $staff = User::factory()->create(['role' => 'super_admin', 'status' => 'active', 'name' => 'Office Person']);
        $ticket = app(SupportTickets::class)->create($dealer, ['category' => 'withdrawal', 'subject' => 'Late', 'body' => 'My withdrawal is late.']);
        app(SupportTickets::class)->reply($staff, $ticket['id'], 'We sent it today.');

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/help')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('data.support_tickets.0.replies', 1)
                ->where('data.support_tickets.0.replies.0.body', 'We sent it today.')
                ->where('data.support_tickets.0.replies.0.is_staff', true)
                ->missing('data.support_tickets.0.replies.0.author_name'));
    }

    public function test_one_partner_never_sees_another_partners_old_requests(): void
    {
        $dealer = $this->dealer();
        $other = $this->dealer(['phone' => '01710000103', 'dealer_code' => 'MNHC-HUB2']);
        $this->actingAs($other)->onPortal('dealer')->post('/commands/partner-support', [
            'topic' => 'support',
            'message' => 'A private question from the other dealer.',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/help')
            ->assertInertia(fn ($page) => $page->has('data.earlier_support_requests', 0));
    }

    public function test_a_public_message_with_a_dealers_code_does_not_appear_as_their_request(): void
    {
        $dealer = $this->dealer();
        DB::table('enquiries')->insert([
            'name' => 'Someone Else',
            'phone' => '01899999999',
            'topic' => 'support',
            'message' => '[MNHC-HUB1] Typed by a stranger on the public contact form.',
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/help')
            ->assertInertia(fn ($page) => $page->has('data.earlier_support_requests', 0));
    }

    public function test_the_old_form_address_still_accepts_requests(): void
    {
        $dealer = $this->dealer();

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/partner-support', ['topic' => 'support', 'message' => 'Sent from a page opened before the update.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('enquiries', ['name' => $dealer->name, 'topic' => 'support', 'status' => 'new']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function categoriesFromTheOldForm(): array
    {
        return ['complaint' => ['complaint'], 'NID correction' => ['kyc_correction']];
    }

    #[DataProvider('categoriesFromTheOldForm')]
    public function test_the_one_form_keeps_every_topic_the_old_form_had(string $category): void
    {
        $dealer = $this->dealer();

        $this->actingAs($dealer)->onPortal('dealer')
            ->post('/commands/support-tickets', ['category' => $category, 'subject' => 'A subject', 'body' => 'A description.'])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('support_tickets', ['user_id' => $dealer->id, 'category' => $category]);
        foreach (['en', 'bn'] as $locale) {
            $this->assertArrayHasKey('support_cat_'.$category, require lang_path($locale.'/portal.php'));
        }
    }

    public function test_only_one_support_form_is_left_on_partner_pages(): void
    {
        $this->assertStringNotContainsString('/commands/partner-support', file_get_contents(resource_path('js/pages/Portal.tsx')));
        $this->assertStringContainsString('/commands/support-tickets', file_get_contents(resource_path('js/components/HelpSupportHub.tsx')));
    }

    public function test_the_new_order_menu_row_goes_to_buying(): void
    {
        $this->assertStringContainsString(
            "mother('new-order', 'purchase', pick(available, ['purchase']))",
            file_get_contents(resource_path('js/shared/portalNav.ts')),
        );
        $this->actingAs($this->dealer())->onPortal('dealer')
            ->get('/purchase')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('section', 'purchase'));
    }

    public function test_renamed_labels_and_jargon_hints_exist_in_both_languages(): void
    {
        $bangla = require lang_path('bn/portal.php');
        $this->assertSame('আয়ের খাতা', $bangla['wallet_statement']);
        $hubBangla = require lang_path('bn/partner_hub.php');
        $hubEnglish = require lang_path('en/partner_hub.php');
        $this->assertSame('এক্সেলে নিন', $hubBangla['export_excel']);
        $this->assertSame(array_keys($hubEnglish), array_keys($hubBangla));
        foreach (['mo', 'am', 'sm', 'me', 'tds'] as $term) {
            $this->assertNotSame($hubEnglish['jargon_'.$term], $hubBangla['jargon_'.$term], $term);
        }
    }

    public function test_partner_pages_receive_the_new_labels(): void
    {
        $this->actingAs($this->dealer())->onPortal('dealer')
            ->get('/circulars')
            ->assertInertia(fn ($page) => $page->where('translations.partner_hub.money_title', 'My money'));
    }
}
