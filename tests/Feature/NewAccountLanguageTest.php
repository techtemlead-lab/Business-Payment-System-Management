<?php

namespace Tests\Feature;

use App\Models\PlaceCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * New accounts start in Bangla whatever language the browser asks for, and the
 * person's own language choice afterwards still wins.
 */
class NewAccountLanguageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_row_added_directly_with_no_language_is_bangla(): void
    {
        $id = DB::table('users')->insertGetId([
            'name' => 'Direct Row',
            'email' => 'direct.row@example.test',
            'password' => 'not-a-real-hash',
            'role' => 'dealer',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('bn', DB::table('users')->where('id', $id)->value('locale'));
    }

    public function test_a_new_account_is_bangla_before_and_after_saving(): void
    {
        $unsaved = new User;
        $this->assertSame('bn', $unsaved->locale);
        $this->assertSame('bn', $unsaved->preferredLocale());

        $saved = $this->newDealerWithNoLanguageSet();
        $this->assertSame('bn', $saved->locale);
        $this->assertSame('bn', $saved->preferredLocale());
    }

    public function test_a_new_account_opens_the_portal_in_bangla_even_with_an_english_browser(): void
    {
        $dealer = $this->newDealerWithNoLanguageSet();

        $this->actingAs($dealer)->onPortal('dealer')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'bn'));
    }

    public function test_an_applicant_on_an_english_browser_still_gets_a_bangla_account_and_notices(): void
    {
        Config::set('madina.sms.driver', 'array');
        Config::set('madina.sms.sent', []);

        $this->onPortal('public')
            ->withHeader('Accept-Language', 'en-US,en;q=0.9')
            ->from('/partnership')
            ->post('/partnership/apply', [
                'name' => 'English Browser Applicant',
                'email' => 'english.browser@example.test',
                'phone' => '01720000195',
                'date_of_birth' => '1990-01-01',
                'role' => 'dealer',
                'division' => 'Khulna',
                'district' => 'Khulna',
            ])
            ->assertRedirect('/partnership#partner-apply-status');
        $id = DB::table('dealer_applications')->where('email', 'english.browser@example.test')->value('id');

        $reviewer = User::factory()->create(['role' => 'super_admin', 'status' => 'active']);
        $this->actingAs($reviewer)->onPortal('sadmin')
            ->post('/commands/operations/staff/applications/'.$id.'/review', [
                'decision' => 'approved',
                'reason' => 'Area checked.',
                'place_code' => PlaceCode::factory()->division()->create(['name' => 'Khulna'])->code,
            ])
            ->assertRedirect();

        $partner = User::query()->where('email', 'english.browser@example.test')->firstOrFail();
        $this->assertSame('bn', $partner->locale);
        $this->assertDatabaseHas('account_notices', [
            'user_id' => $partner->id,
            'title' => __('portal.notice_partnership_approved_title', [], 'bn'),
        ]);
    }

    public function test_a_later_language_choice_still_wins(): void
    {
        $dealer = $this->newDealerWithNoLanguageSet();

        $this->actingAs($dealer)->onPortal('dealer')->from('/')->post('/locale', ['locale' => 'en'])->assertRedirect();

        $chosen = $dealer->fresh();
        $this->assertSame('en', $chosen->locale);
        $this->assertSame('en', $chosen->preferredLocale());
        $this->actingAs($chosen)->onPortal('dealer')->get('/')
            ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    }

    private function newDealerWithNoLanguageSet(): User
    {
        $user = (new User)->forceFill([
            'name' => 'New Dealer',
            'email' => 'new.dealer.'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'not-a-real-password-1',
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MN-'.Str::upper(Str::random(12)),
        ]);
        $user->save();

        return $user->fresh();
    }
}
