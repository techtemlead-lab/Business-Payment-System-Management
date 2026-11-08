<?php

namespace Tests\Feature;

use App\Domain\Support\TourGuide;
use App\Domain\Support\UserManual;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UserManualRoundTwoTest extends TestCase
{
    use RefreshDatabase;

    private const OfficeChapters = [
        'menu_office', 'commission_tabs', 'report_centre', 'website_editor', 'stock_batches', 'stock_recall_transfers',
        'purchasing_goods', 'stock_counting', 'parcel_received_back', 'mixed_delivery_decision', 'manual_couriers',
        'global_search', 'company_funds_heads', 'accounting_statements', 'statement_matching', 'payout_file',
        'product_facts', 'product_reviews', 'trust_pages',
    ];

    private const PartnerChapters = [
        'menu_partner', 'my_money_tabs', 'support_one_form', 'repeat_order', 'alerts_next_steps',
        'sales_kit_partner', 'customer_book', 'schemes_partner',
    ];

    private const SharedChapters = [
        'verify_dealer', 'practitioner_directory', 'patient_records', 'prescription_templates_followups', 'prescription_dosing',
    ];

    public function test_new_chapters_have_matching_bangla_and_english_text(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $keys = [...self::OfficeChapters, ...self::PartnerChapters, ...self::SharedChapters, 'sales_kit_schemes_owner'];

        app()->setLocale('en');
        $english = collect(app(UserManual::class)->chaptersFor($owner))->keyBy('key');
        app()->setLocale('bn');
        $bangla = collect(app(UserManual::class)->chaptersFor($owner))->keyBy('key');

        foreach ($keys as $key) {
            $this->assertTrue($english->has($key), "$key is not registered");
            foreach (['badge', 'title', 'summary', 'purpose', 'why'] as $field) {
                $this->assertNotEmpty($english[$key][$field], "$key en $field");
                $this->assertNotEmpty($bangla[$key][$field], "$key bn $field");
                $this->assertNotSame($english[$key][$field], $bangla[$key][$field], "$key $field is not translated");
            }
            foreach (['steps', 'outcomes', 'tips', 'warnings'] as $list) {
                $this->assertNotEmpty($english[$key][$list], "$key en $list");
                $this->assertCount(count($english[$key][$list]), $bangla[$key][$list], "$key $list differ between languages");
            }
        }
    }

    public function test_chapters_show_only_to_the_right_roles(): void
    {
        $manual = app(UserManual::class);
        $keysFor = fn (User $user): array => array_column($manual->chaptersFor($user), 'key');

        $dealer = $keysFor(User::factory()->create(['role' => 'dealer', 'status' => 'active']));
        $subDealer = $keysFor(User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']));
        $staff = $keysFor(User::factory()->create(['role' => 'employee', 'status' => 'active', 'profile' => ['permissions' => ['orders']]]));
        $owner = $keysFor(User::factory()->create(['role' => 'super_admin', 'status' => 'active']));

        foreach (self::OfficeChapters as $key) {
            $this->assertNotContains($key, $dealer, "Dealers must not get $key");
            $this->assertNotContains($key, $subDealer, "Sub-dealers must not get $key");
            $this->assertContains($key, $staff);
            $this->assertContains($key, $owner);
        }
        foreach (self::PartnerChapters as $key) {
            $this->assertContains($key, $dealer);
            $this->assertContains($key, $subDealer);
        }
        foreach ([$dealer, $subDealer, $staff] as $keys) {
            $this->assertNotContains('sales_kit_schemes_owner', $keys);
        }
        $this->assertContains('sales_kit_schemes_owner', $owner);
    }

    public function test_the_text_keeps_the_owners_rules(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        app()->setLocale('en');
        $chapters = collect(app(UserManual::class)->chaptersFor($owner))->keyBy('key');

        $this->assertStringContainsString('not a full set of accounts yet', implode(' ', $chapters['accounting_statements']['warnings']));
        $this->assertStringContainsString('sales and purchases are not in the journal', implode(' ', $chapters['accounting_statements']['warnings']));
        $this->assertStringContainsString('Not even the owner', implode(' ', $chapters['prescriptions_partner']['outcomes']));
        $this->assertStringContainsString('cannot see a partner', implode(' ', $chapters['clinic_prescriptions']['tips']));
        $this->assertStringContainsString('does not change your order total', implode(' ', $chapters['schemes_partner']['warnings']));

        foreach ([...self::OfficeChapters, ...self::PartnerChapters, ...self::SharedChapters, 'sales_kit_schemes_owner'] as $key) {
            $text = mb_strtolower(json_encode($chapters[$key], JSON_UNESCAPED_UNICODE));
            foreach (['second person', 'someone else', 'two-person', 'two people'] as $held) {
                $this->assertStringNotContainsString($held, $text, "$key describes the approval rules still being changed");
            }
        }
    }

    public function test_every_tour_step_points_at_something_the_screens_really_draw(): void
    {
        $screens = collect(File::allFiles(resource_path('js')))
            ->filter(fn ($file): bool => in_array($file->getExtension(), ['tsx', 'ts'], true))
            ->map(fn ($file): string => $file->getContents())
            ->implode("\n");
        $people = [
            User::factory()->create(['role' => 'super_admin', 'status' => 'active']),
            User::factory()->create(['role' => 'account_manager', 'status' => 'active']),
            User::factory()->create(['role' => 'dealer', 'status' => 'active']),
            User::factory()->create(['role' => 'sub_dealer', 'status' => 'active']),
        ];

        foreach ($people as $person) {
            foreach (app(TourGuide::class)->definitionsFor($person) as $pageKey => $tour) {
                foreach ($tour['steps'] as $step) {
                    foreach (array_map('trim', explode(',', $step['target'])) as $selector) {
                        $this->assertTrue($this->drawn($selector, $screens), "$person->role tour $pageKey targets missing $selector");
                    }
                }
            }
        }
    }

    public function test_each_page_tour_only_uses_targets_found_on_that_page(): void
    {
        $dealer = app(TourGuide::class)->definitionsFor(User::factory()->create(['role' => 'dealer', 'status' => 'active']));

        $this->assertSame(['[data-tour="profile-panel"]'], array_column($dealer['profile']['steps'], 'target'));
        $this->assertSame(['[data-tour="security-link"]'], array_column($dealer['security']['steps'], 'target'));
        $this->assertSame(['[data-tour="catalog-grid"]'], array_column($dealer['catalog']['steps'], 'target'));
        $this->assertSame(['[data-tour="purchase-matrix"]'], array_column($dealer['purchase']['steps'], 'target'));
    }

    private function drawn(string $selector, string $screens): bool
    {
        if (preg_match('/^\[data-(tour|testid)="([^"]+)"\]$/', $selector, $match) === 1) {
            return str_contains($screens, 'data-'.$match[1].'="'.$match[2].'"');
        }
        if (str_starts_with($selector, '.')) {
            return preg_match('/className="[^"]*\b'.preg_quote(substr($selector, 1), '/').'(?![\w-])/', $screens) === 1;
        }

        return false;
    }
}
