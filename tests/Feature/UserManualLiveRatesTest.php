<?php

namespace Tests\Feature;

use App\Domain\Commissions\CommissionPolicy;
use App\Domain\Support\UserManual;
use App\Models\User;
use Database\Seeders\CommissionRuleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

/**
 * The user manual quotes rates and targets from the live commission rules, so it never
 * promises something the system does not pay.
 */
class UserManualLiveRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(CommissionRuleSeeder::class);
    }

    public function test_sub_dealer_chapter_uses_the_live_salary_and_tour_rates_with_no_minimum(): void
    {
        app()->setLocale('en');
        $chapter = $this->chapter(User::factory()->create(['role' => 'sub_dealer']), 'subdealer_salary_tour');

        $this->assertStringContainsString('3% salary on every purchase', $chapter['purpose']);
        $this->assertStringContainsString('no minimum purchase', $chapter['purpose']);
        $this->assertStringContainsString('Another 3% is saved in their own tour fund', $chapter['purpose']);
        $text = $this->text($chapter);
        $this->assertStringNotContainsString('5%', $text);
        $this->assertStringNotContainsString('2%', $text);
        $this->assertStringNotContainsStringIgnoringCase('minimum qualifying', $text);
        $this->assertStringNotContainsStringIgnoringCase('sponsor', $text);
    }

    public function test_changing_the_rules_changes_the_manual(): void
    {
        $owner = User::factory()->create(['role' => 'super_admin']);
        app(CommissionPolicy::class)->update($owner, [
            'sub_dealer_salary' => ['percentage' => '4.00'],
            'sub_dealer_tour' => ['percentage' => '2.00'],
            'rank_royalty' => ['parameters' => ['mo_target' => '300000.00']],
        ]);
        app()->setLocale('en');

        $sub = $this->chapter(User::factory()->create(['role' => 'sub_dealer']), 'subdealer_salary_tour');
        $this->assertStringContainsString('4% salary', $sub['purpose']);
        $this->assertStringContainsString('Another 2% is saved', $sub['purpose']);

        $ranks = $this->chapter(User::factory()->create(), 'ranks_royalties');
        $this->assertStringContainsString('must reach ৳3,00,000', implode(' ', $ranks['tips']));
    }

    public function test_dealer_chapters_quote_the_default_rules(): void
    {
        app()->setLocale('en');
        $dealer = User::factory()->create();

        $pool = $this->chapter($dealer, 'commissions_pool');
        $this->assertStringContainsString('40% of each dealer purchase and 50% of each sub-dealer purchase', $pool['purpose']);
        $this->assertStringContainsString('the company keeps 60% (dealer purchase) or 50% (sub-dealer purchase)', $this->text($pool));

        $network = $this->text($this->chapter($dealer, 'network_genealogy'));
        $this->assertStringContainsString('10% to the direct referrer, 2% to the referrer one level above and 1% to the one above that', $network);

        $area = $this->text($this->chapter($dealer, 'area_dealers_places'));
        $this->assertStringContainsString('10% area fund', $area);
        $this->assertStringContainsString('division 2%, district 2%, upazila 2%, union 2%, management 2%', $area);

        $ranks = $this->chapter($dealer, 'ranks_royalties');
        $tips = implode(' ', $ranks['tips']);
        $this->assertStringContainsString('must reach ৳2,50,000 (one-time award ৳5,000)', $tips);
        $this->assertStringContainsString('AM: 10 active dealers at MO or higher within 3 levels', $tips);
        $this->assertStringContainsString('(award ৳50,000)', $tips);
        $this->assertStringContainsString('MO ৳5,000, AM ৳15,000, SM ৳30,000, ME ৳50,000', $tips);
        $this->assertStringContainsString('first 30 days', $tips);
        $this->assertStringContainsString('4% of every purchase', $ranks['purpose']);

        $rewards = $this->text($this->chapter($dealer, 'rewards_milestones'));
        $this->assertStringContainsString('bike ৳50,00,000, car ৳25,00,00,000', $rewards);
        $this->assertStringContainsString('৳8,00,000 for the bike, ৳2,00,00,000 for the car', $rewards);
    }

    public function test_bangla_manual_shows_bangla_digits_and_no_placeholder_is_left_in_either_language(): void
    {
        $owner = User::factory()->staff('super_admin')->create();
        foreach (['en', 'bn'] as $locale) {
            app()->setLocale($locale);
            $chapters = app(UserManual::class)->chaptersFor($owner);
            foreach ($chapters as $chapter) {
                $this->assertDoesNotMatchRegularExpression('/:[a-z]+_[a-z_]+/', $this->text($chapter), $locale.' '.$chapter['key']);
            }
        }

        app()->setLocale('bn');
        $sub = $this->chapter(User::factory()->create(['role' => 'sub_dealer']), 'subdealer_salary_tour');
        $this->assertStringContainsString('৩% বেতন', $sub['purpose']);
        $this->assertStringContainsString('আরও ৩% তাদের নিজের ট্যুর ফান্ডে', $sub['purpose']);
        $this->assertStringContainsString('কোনো ন্যূনতম কেনা লাগে না', $sub['purpose']);
        $this->assertStringContainsString('৳২,৫০,০০০', implode(' ', $this->chapter(User::factory()->create(), 'ranks_royalties')['tips']));
    }

    public function test_portal_pages_send_the_manual_without_reading_rules_and_load_the_rates_afterwards(): void
    {
        $sub = User::factory()->create(['role' => 'sub_dealer']);

        $this->actingAs($sub)->onPortal('subdealer')->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->missing('manualRuleValues')
                ->where('userManualChapters', fn ($chapters) => str_contains(
                    (string) collect($chapters)->firstWhere('key', 'subdealer_salary_tour')['purpose'],
                    ':sub_salary_rate%',
                ))
                ->loadDeferredProps('manual', fn ($reload) => $reload
                    ->where('manualRuleValues.sub_salary_rate', '3')
                    ->where('manualRuleValues.sub_tour_rate', '3')
                    ->where('manualRuleValues.mo_target', '৳2,50,000')));
    }

    public function test_sub_dealers_see_the_bike_and_car_reward_chapter(): void
    {
        $chapters = collect(app(UserManual::class)->chaptersFor(User::factory()->create(['role' => 'sub_dealer'])));

        $this->assertTrue($chapters->contains('key', 'rewards_milestones'));
        $this->assertFalse($chapters->contains('key', 'ranks_royalties'));
    }

    public function test_the_old_unused_manual_copy_with_wrong_rates_is_gone(): void
    {
        foreach (['en', 'bn'] as $locale) {
            $portal = require lang_path($locale.'/portal.php');
            $this->assertSame([], array_values(array_filter(array_keys($portal), fn (string $key): bool => str_starts_with($key, 'manual_ch_'))));
        }
    }

    /** @return array<string, mixed> */
    private function chapter(User $user, string $key): array
    {
        $chapter = app(UserManual::class)->chapter($key, $user);
        $this->assertNotNull($chapter, $key);

        return $chapter;
    }

    /** @param array<string, mixed> $chapter */
    private function text(array $chapter): string
    {
        return Collection::make([$chapter['title'], $chapter['summary'], $chapter['purpose'], $chapter['why'],
            ...$chapter['steps'], ...$chapter['outcomes'], ...$chapter['tips'], ...$chapter['warnings']])->filter()->implode(' ');
    }
}
