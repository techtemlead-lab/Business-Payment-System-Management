<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** CR-19: the office profile page (owner and staff). */
class OfficeProfilePageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_approval_line_is_gone_in_both_languages(): void
    {
        foreach (['bn', 'en'] as $locale) {
            $strings = require lang_path($locale.'/portal.php');
            $this->assertArrayNotHasKey('profile_owner_edit_notice', $strings, $locale);
            $this->assertNotSame('', $strings['owner_profile_edit_title'], $locale);
        }
        $this->assertStringNotContainsString('profile_owner_edit_notice', (string) file_get_contents(resource_path('js/components/office/sections/Profile.tsx')));
    }

    public function test_the_owner_is_the_company_owner_and_others_keep_their_own_title(): void
    {
        $this->assertSame('কোম্পানির মালিক', trans('cr15.profile_role_owner', [], 'bn'));
        $this->assertSame('Company owner', trans('cr15.profile_role_owner', [], 'en'));

        $screen = (string) file_get_contents(resource_path('js/components/office/sections/OfficeProfile.tsx'));
        $this->assertMatchesRegularExpression("/role === 'super_admin' \? translate\('cr15', 'profile_role_owner'\) : label\(role, translate\)/", $screen);
        // Only the Super Admin is the "owner": the MD and the Chairman show their own titles.
        $this->assertStringNotContainsString("'md'", explode('export default function', $screen)[0]);
    }

    public function test_the_owner_and_staff_open_the_profile_page_and_the_owner_form_stays(): void
    {
        $owner = User::factory()->staff()->create();
        $support = User::factory()->staff('support')->create();

        $this->actingAs($owner)->onPortal('sadmin')->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'profile')->where('auth.user.role', 'super_admin'));
        $this->actingAs($support)->onPortal('manage')->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('section', 'profile')->where('auth.user.role', 'support'));
    }

    public function test_an_uploaded_photo_is_kept_and_shown_again_after_a_reload(): void
    {
        Storage::fake('public');
        $owner = User::factory()->staff()->create();

        $this->actingAs($owner)->onPortal('sadmin')->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('owner.jpg', 300, 300),
        ])->assertRedirect();

        $stored = $owner->fresh()->profile['photo_url'];
        $this->assertStringStartsWith('/storage/staff-photos/'.$owner->id.'/', $stored);
        $this->assertCount(1, Storage::disk('public')->allFiles('staff-photos/'.$owner->id));

        $this->actingAs($owner->fresh())->onPortal('sadmin')->get('/profile')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('auth.user.photo_url', $stored));
    }
}
