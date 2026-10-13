<?php
namespace Tests\Feature;

use App\Models\{Product,User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PublicWebsiteTest extends TestCase
{
    use RefreshDatabase;
    protected function setUp():void {parent::setUp();$this->withoutVite();}

    public function test_catalog_filters_public_products_and_uses_exact_price_order():void
    {
        foreach([['A','200.01',4,true],['B','200.02',0,true],['C','90.00',4,false]] as [$name,$price,$stock,$active])
            Product::create(['sku'=>'TEST-'.$name,'name'=>'Public '.$name,'category'=>'Care','mrp'=>$price,'dealer_price'=>'50.00','stock'=>$stock,'active'=>$active]);
        $this->get('/products?sort=price_desc')->assertOk()->assertSeeInOrder(['Public B','Public A'])->assertDontSee('Public C')->assertDontSee('50.00 Tk');
        $this->get('/products?in_stock=1&min_price=200.01&max_price=200.01')->assertOk()->assertSee('Public A')->assertDontSee('Public B');
        $this->get('/products?min_price=invalid')->assertSessionHasErrors('min_price');
        $this->get('/products/preview/reference-product-01')->assertNotFound();
    }

    public function test_directory_only_publishes_consented_active_dealers():void
    {
        $visible=User::factory()->create(['role'=>'dealer','status'=>'active','name'=>'Published Partner','directory_visible'=>true,'division'=>'Dhaka','district'=>'Dhaka','phone'=>'PUBLIC-TEST-PHONE','nid'=>'PRIVATE-NID','profile'=>['father_name'=>'PRIVATE-PARENT']]);
        User::factory()->create(['name'=>'Hidden Partner','directory_visible'=>false]);
        User::factory()->create(['name'=>'Suspended Partner','status'=>'suspended','directory_visible'=>true]);
        User::factory()->create(['name'=>'Staff Partner','role'=>'super_admin','directory_visible'=>true]);
        $this->get('/dealers?district=Dhaka')->assertOk()->assertSee('Published Partner')->assertSee('PUBLIC-TEST-PHONE')->assertDontSee('Hidden Partner')->assertDontSee('Suspended Partner')->assertDontSee('Staff Partner')->assertDontSee('PRIVATE-NID')->assertDontSee('PRIVATE-PARENT')->assertDontSee($visible->email);
    }

    public function test_enquiry_is_saved_without_creating_an_account_and_rejects_honeypot():void
    {
        $data=['name'=>'Synthetic Visitor','phone'=>'TEST-PHONE','topic'=>'Dealer enquiry','message'=>'Please share dealer information.'];
        $this->from('/contact')->post('/enquiries',$data)->assertRedirect('/contact')->assertSessionHas('success');
        $this->assertDatabaseCount('users',0);$this->assertDatabaseCount('enquiries',1);
        $this->post('/enquiries',[...$data,'website'=>'spam'])->assertSessionHasErrors('website');
        $this->assertDatabaseCount('enquiries',1);
    }

    public function test_cms_text_is_escaped_and_login_uses_staff_created_accounts():void
    {
        DB::table('content_pages')->insert(['slug'=>'about','title'=>'Company information','body'=>'<script>alert("cms")</script>','published'=>true,'created_at'=>now(),'updated_at'=>now()]);
        $this->get('/about')->assertOk()->assertSee('&lt;script&gt;',false)->assertDontSee('<script>alert("cms")</script>',false);
        $this->get('/login')->assertOk()->assertSee('Account Login')->assertSee('/madina-header-logo.png')->assertDontSee('/register');
        $u=User::factory()->create(['status'=>'active','password'=>'TestPassword12345']);
        $this->post('/login',['email'=>$u->email,'password'=>'TestPassword12345'])->assertRedirect('/workspace');
        $this->assertAuthenticatedAs($u);
    }

    public function test_password_recovery_form_resets_an_eligible_account():void
    {
        $u=User::factory()->create(['status'=>'active','password'=>'PreviousPassword123']);
        $token=\Illuminate\Support\Facades\Password::createToken($u);
        $this->get('/reset-password/'.$token.'?email='.urlencode($u->email))->assertOk()->assertSee('Confirm Password');
        $this->post('/reset-password',['email'=>$u->email,'token'=>$token,'password'=>'ReplacementPassword123','password_confirmation'=>'ReplacementPassword123'])->assertRedirect('/login');
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('ReplacementPassword123',$u->fresh()->password));
        $this->post('/reset-password',['email'=>$u->email,'token'=>$token,'password'=>'AnotherPassword123','password_confirmation'=>'AnotherPassword123'])->assertSessionHasErrors('email');
    }
}

