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

    public function test_nearest_dealer_search_uses_browser_gps_coordinates_and_calculates_distance():void
    {
        $near = User::factory()->create([
            'role'=>'dealer','status'=>'active','name'=>'Nearby Savar Dealer',
            'dealer_code'=>'D-SAVAR-01','directory_visible'=>true,
            'division'=>'Dhaka','district'=>'Dhaka',
            'latitude'=>'23.914000','longitude'=>'90.260000',
            'profile'=>['upazila'=>'Savar','pharmacy'=>'Madina Health Point']
        ]);
        $far = User::factory()->create([
            'role'=>'dealer','status'=>'active','name'=>'Far Chittagong Dealer',
            'dealer_code'=>'D-CTG-01','directory_visible'=>true,
            'division'=>'Chittagong','district'=>'Chittagong',
            'latitude'=>'22.356900','longitude'=>'91.783200'
        ]);

        // Default view: shows location button and dealers
        $res = $this->get('/dealers');
        $res->assertOk();
        $res->assertSee('Use My Current Location');
        $res->assertSee('Nearby Savar Dealer');
        $res->assertSee('Far Chittagong Dealer');

        // With user GPS coordinates close to Savar (Lat 23.91, Lng 90.26)
        $gpsRes = $this->get('/dealers?lat=23.910000&lng=90.260000');
        $gpsRes->assertOk();
        $gpsRes->assertSeeInOrder(['Nearby Savar Dealer', 'Far Chittagong Dealer']);
        $gpsRes->assertSee('km away');
        $gpsRes->assertSee('Madina Health Point');
        $gpsRes->assertSee('Showing dealers sorted by distance to your detected location.');
    }

    public function test_contact_page_renders_office_location_map_and_directions():void
    {
        $res = $this->get('/contact');
        $res->assertOk();
        $res->assertSee('Office &amp; Healthcare Center Location', false);
        $res->assertSee('openstreetmap.org/export/embed.html', false);
        $res->assertSee('Open Map Directions');
    }

    public function test_about_page_renders_structured_history_mission_vision_objectives():void
    {
        $res = $this->get('/about');
        $res->assertOk();
        $res->assertSee('Company History');
        $res->assertSee('Our Mission');
        $res->assertSee('Our Vision');
        $res->assertSee('Core Objectives');
        $res->assertSee('Authentic Wellness:');
        $res->assertSee('Empowered Partners:');
    }

    public function test_home_page_renders_special_campaigns_and_header_navigation():void
    {
        $res = $this->get('/');
        $res->assertOk();
        $res->assertSee('Special Discounts &amp; Promotional Offers', false);
        $res->assertSee('SPECIAL CAMPAIGN &amp; OFFERS', false);
        $res->assertSee('Healthcare Entrepreneur &amp; Partner Package', false);
        $res->assertSee('Family Wellness &amp; Immunity Kit', false);
        $res->assertSee('Save 10,000 Tk');
        $res->assertSee('About Us');
        $res->assertSee('Contact');
    }
}

