<?php
namespace Tests\Feature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Notification,Password};
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanyAccessTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void{parent::setUp();$this->withoutVite();}
    public function test_company_entry_is_separate_and_guest_pages_are_private():void
    {
        $this->get('/company')->assertRedirect('/company/login');
        $this->get('/company/orders')->assertRedirect('/company/login');
        $this->get('/company/login')->assertOk()->assertSee('Staff Sign In')->assertDontSee('portal-category-nav')->assertHeader('Cache-Control','max-age=0, no-store, private');
        $this->get('/')->assertDontSee('/company/login');
    }
    public function test_correct_credentials_do_not_cross_company_and_dealer_login():void
    {
        $staff=User::factory()->create(['role'=>'super_admin','password'=>'StaffPassword12345']);
        $dealer=User::factory()->create(['role'=>'dealer','password'=>'DealerPassword12345']);
        $this->post('/company/login',['email'=>$dealer->email,'password'=>'DealerPassword12345'])->assertSessionHasErrors('email');$this->assertGuest();
        $this->post('/login',['email'=>$staff->email,'password'=>'StaffPassword12345'])->assertSessionHasErrors('email');$this->assertGuest();
        $this->post('/company/login',['email'=>$staff->email,'password'=>'StaffPassword12345'])->assertRedirect('/company');$this->assertAuthenticatedAs($staff);
        $this->get('/company')->assertInertia(fn(Assert $p)=>$p->where('portalBase','/company'));
        $this->get('/workspace/orders?q=TEST')->assertRedirect('/company/orders?q=TEST');
        $this->post('/logout')->assertRedirect('/company/login');$this->assertGuest();
    }
    public function test_dealer_cannot_open_company_area_and_staff_permissions_still_apply():void
    {
        $this->actingAs(User::factory()->create(['role'=>'dealer']))->get('/company')->assertForbidden();
        $this->get('/company/accounts')->assertForbidden();
        $editor=User::factory()->create(['role'=>'editor']);
        $this->actingAs($editor)->get('/company/content')->assertOk();
        $this->get('/company/payments')->assertForbidden();
        $this->get('/company/orders')->assertForbidden();
    }
    public function test_staff_recovery_link_and_reset_return_to_company_access():void
    {
        Notification::fake();$staff=User::factory()->create(['role'=>'finance']);
        $this->post('/company/forgot-password',['email'=>$staff->email])->assertSessionHas('success');
        Notification::assertSentTo($staff,\Illuminate\Auth\Notifications\ResetPassword::class,function($notification)use($staff){$mail=$notification->toMail($staff);return str_starts_with($mail->actionUrl,url('/company/reset-password/'));});
        $token=Password::createToken($staff);
        $this->get('/company/reset-password/'.$token.'?email='.urlencode($staff->email))->assertOk()->assertSee('Confirm Password');
        $this->post('/company/reset-password',['token'=>$token,'email'=>$staff->email,'password'=>'ReplacementPassword123','password_confirmation'=>'ReplacementPassword123'])->assertRedirect('/company/login');
    }
    public function test_inertia_logout_uses_full_navigation_to_standalone_company_login():void
    {
        $this->actingAs(User::factory()->create(['role'=>'super_admin']))->withHeader('X-Inertia','true')->post('/logout')->assertStatus(409)->assertHeader('X-Inertia-Location','/company/login');
        $this->assertGuest();
    }
}

