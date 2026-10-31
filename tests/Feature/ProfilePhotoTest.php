<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PortalHost;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_replace_their_profile_photo(): void
    {
        Storage::fake('public');
        $owner = User::factory()->staff('super_admin')->create([
            'profile' => ['photo_url' => '/storage/staff-photos/placeholder/old.jpg'],
        ]);

        $this->actingAs($owner)->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('owner.jpg', 240, 240),
        ])->assertRedirect()->assertSessionHas('success');

        $fresh = $owner->fresh();
        $files = Storage::disk('public')->allFiles('staff-photos/'.$owner->id);
        $this->assertCount(1, $files);
        $this->assertSame(parse_url(Storage::disk('public')->url($files[0]), PHP_URL_PATH), $fresh->profile['photo_url']);
        $this->assertSame($fresh->profile['photo_url'], $fresh->profile['avatar_url']);
        $this->assertStringStartsWith('/storage/staff-photos/'.$owner->id.'/', $fresh->profile['photo_url']);

        $this->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('replacement.png', 180, 180),
        ])->assertRedirect()->assertSessionHas('success');

        $replaced = Storage::disk('public')->allFiles('staff-photos/'.$owner->id);
        $this->assertCount(1, $replaced);
        $this->assertNotSame($files[0], $replaced[0]);
        $this->assertSame(parse_url(Storage::disk('public')->url($replaced[0]), PHP_URL_PATH), $owner->fresh()->profile['photo_url']);
    }

    public function test_preview_origin_upload_returns_to_that_origin(): void
    {
        Storage::fake('public');
        $owner = User::factory()->staff('super_admin')->create();

        $this->withUnencryptedCookie(PortalHost::PreviewCookie, 'sadmin');
        $this->actingAs($owner)
            ->from('http://sadmin.example.test/profile')
            ->post('/commands/profile/photo', [
                'photo' => UploadedFile::fake()->image('owner.png', 64, 64),
            ], ['Host' => '127.0.0.1:8088'])
            ->assertRedirect('http://127.0.0.1:8088/profile');
    }

    public function test_employee_can_upload_a_profile_photo(): void
    {
        Storage::fake('public');
        $employee = User::factory()->staff('employee')->create();

        $this->actingAs($employee)->onPortal('manage')->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('staff.jpg', 120, 120),
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertNotEmpty(Storage::disk('public')->allFiles('staff-photos/'.$employee->id));
    }

    public function test_partner_cannot_use_the_workspace_photo_upload(): void
    {
        Storage::fake('public');
        $dealer = User::factory()->create(['role' => 'dealer', 'status' => 'active']);

        $this->actingAs($dealer)->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('dealer.jpg', 80, 80),
        ])->assertForbidden();

        $this->assertSame([], Storage::disk('public')->allFiles('staff-photos/'.$dealer->id));
    }

    public function test_guest_is_sent_to_sign_in(): void
    {
        $this->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->image('guest.jpg', 80, 80),
        ])->assertRedirect('/login');
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Storage::fake('public');
        $owner = User::factory()->staff('super_admin')->create();

        $this->actingAs($owner)->post('/commands/profile/photo', [
            'photo' => UploadedFile::fake()->create('notes.txt', 20, 'text/plain'),
        ])->assertSessionHasErrors('photo');

        $this->assertNull($owner->fresh()->profile['photo_url'] ?? null);
    }

    public function test_admin_home_uses_avatar_url_when_photo_url_is_absent(): void
    {
        $owner = User::factory()->staff('super_admin')->create([
            'profile' => ['avatar_url' => '/storage/staff-photos/3/face.png'],
        ]);

        $this->actingAs($owner)->onPortal('sadmin')->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.admin_home.profile.photo_url', '/storage/staff-photos/3/face.png')
                ->where('auth.user.photo_url', '/storage/staff-photos/3/face.png'));
    }
}
