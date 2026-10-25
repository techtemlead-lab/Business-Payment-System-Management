<?php

namespace Tests\Feature;

use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicSiteLocaleChromeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_bangla_locale_localizes_public_home_catalog_footer_and_partnership_chrome(): void
    {
        Product::create([
            'sku' => 'BN-CHROME-1',
            'name' => 'Bangla Chrome Product',
            'category' => 'Care',
            'mrp' => '120.00',
            'dealer_price' => '80.00',
            'stock' => 5,
            'active' => true,
        ]);

        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])->assertRedirect('/');

        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('আমাদের কোম্পানি', false)
            ->assertSee('ডিলার খুঁজুন', false)
            ->assertSee('কোম্পানি নোটিশ', false)
            ->assertSee('এশিয়া/ঢাকা · BDT', false);

        $this->onPortal('public')->get('/products')
            ->assertOk()
            ->assertSee('আমাদের পণ্য ক্যাটালগ', false)
            ->assertSee('ফিল্টার প্রয়োগ', false)
            ->assertSee('কম স্টক', false)
            ->assertSee('ছবি শীঘ্রই আসছে', false);

        $this->onPortal('public')->get('/notice')
            ->assertOk()
            ->assertSee('কোম্পানি নোটিশ', false)
            ->assertSee('এখনো কোনো প্রকাশিত নোটিশ নেই', false);

        $this->onPortal('public')->get('/partnership')
            ->assertOk()
            ->assertSee('পার্টনারশিপ আবেদন জমা দিন', false)
            ->assertSee('আবেদন জমা দিন', false);
    }

    public function test_english_locale_keeps_familiar_public_chrome(): void
    {
        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('Company Notice', false)
            ->assertSee('Asia/Dhaka · BDT', false);

        $this->onPortal('public')->get('/products')
            ->assertOk()
            ->assertSee('Our Product Catalogue', false)
            ->assertSee('Find a Dealer', false);
    }
}
