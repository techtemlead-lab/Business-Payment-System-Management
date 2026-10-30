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

        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])
            ->assertRedirect();

        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('আমাদের কোম্পানি', false)
            ->assertSee('ডিলার খুঁজুন', false)
            ->assertSee('কোম্পানি নোটিশ', false)
            ->assertSee('ঢাকা, বাংলাদেশ', false)
            ->assertSee('প্রাকৃতিক খাদ্য সম্পূরক', false)
            ->assertSee('চ্যাট', false)
            ->assertSee('id="ref-site-ui"', false)
            ->assertSee('অবস্থান সনাক্ত করা হচ্ছে', false)
            ->assertSee('মাদিনা পণ্য, কোম্পানি তথ্য', false)
            ->assertSee('আমাদের পণ্য', false)
            ->assertSee('পার্টনার হোন', false)
            ->assertSee('মোবাইল নেভিগেশন মেনু খুলুন', false)
            ->assertSee('মাদিনা ন্যাচারাল হেলথ কেয়ার হোম', false)
            ->assertDontSee('Natural food supplements, unani medicine', false)
            ->assertDontSee('Detecting your location...', false)
            ->assertDontSee('Madina products, company information and dealer network.', false)
            ->assertDontSee('Open mobile navigation menu', false)
            ->assertDontSee('>CHAT<', false);

        $this->onPortal('public')->get('/products')
            ->assertOk()
            ->assertSee('আমাদের পণ্য ক্যাটালগ', false)
            ->assertSee('ফিল্টার প্রয়োগ', false)
            ->assertSee('কম স্টক', false)
            ->assertSee('পণ্যের বিবরণ দেখুন', false);

        $this->onPortal('public')->get('/notice')
            ->assertOk()
            ->assertSee('কোম্পানি নোটিশ', false)
            ->assertSee('এখনো কোনো প্রকাশিত নোটিশ নেই', false);

        $this->onPortal('public')->get('/partnership')
            ->assertOk()
            ->assertSee('অনুমোদিত মাদিনা পার্টনার হোন', false)
            ->assertSee('বিশ্বস্ত পণ্য পরিসর', false)
            ->assertSee('সুসংগঠিত অনবোর্ডিং', false)
            ->assertSee('পার্টনারশিপ আবেদন জমা দিন', false)
            ->assertSee('আবেদন জমা দিন', false)
            ->assertSee('কেন মাদিনার সাথে পার্টনার হবেন', false)
            ->assertSee('কীভাবে আবেদন করবেন', false)
            ->assertSee('ডিলারশিপের জন্য আবেদন', false)
            ->assertDontSee('Become an Authorized Madina Partner', false)
            ->assertDontSee('Trusted product range', false)
            ->assertDontSee('Why partner with Madina', false)
            ->assertDontSee('Become a Partner', false);

        $this->onPortal('public')->get('/about')
            ->assertOk()
            ->assertSee('মাদিনা ন্যাচারাল হেলথ কেয়ার সম্পর্কে', false)
            ->assertSee('কোম্পানির ইতিহাস', false)
            ->assertSee('আমাদের মিশন', false)
            ->assertSee('প্রামাণিক ওয়েলনেস', false)
            ->assertDontSee('About Madina Natural Health Care', false)
            ->assertDontSee('Company History', false)
            ->assertDontSee('Our Mission', false);

        $this->onPortal('public')->get('/dealers')
            ->assertOk()
            ->assertSee('ডিলার ডিরেক্টরি', false)
            ->assertSee('নিকটতম ডিলার খুঁজুন', false)
            ->assertSee('সব বিভাগ', false)
            ->assertDontSee('Find nearest dealers', false)
            ->assertDontSee('All divisions', false);

        $this->onPortal('public')->get('/contact')
            ->assertOk()
            ->assertSee('যোগাযোগ করুন', false)
            ->assertSee('জিজ্ঞাসা পাঠান', false)
            ->assertSee('আপনার নাম', false)
            ->assertSee('ডিলার জিজ্ঞাসা', false)
            ->assertDontSee('Send an Enquiry', false)
            ->assertDontSee('Your Name', false);

        $this->onPortal('public')->get('/services')
            ->assertOk()
            ->assertSee('প্রাকৃতিক স্বাস্থ্যসেবা ও ফিজিওথেরাপি সেবা', false)
            ->assertSee('প্রাকৃতিক স্বাস্থ্য পরামর্শ', false)
            ->assertDontSee('Natural Health Care & Physiotherapy Services', false);

        $this->onPortal('public')->get('/quality')
            ->assertOk()
            ->assertSee('মান ও প্রামাণিকতার অঙ্গীকার', false)
            ->assertSee('যাচাইকৃত পণ্য মান', false)
            ->assertDontSee('Quality & Authenticity Promise', false);

        $this->onPortal('public')->get('/faq')
            ->assertOk()
            ->assertSee('প্রায়শই জিজ্ঞাসিত প্রশ্ন', false)
            ->assertSee('মাদিনা পণ্য কোথায় কিনব?', false)
            ->assertSee('এখনও সাহায্য দরকার? যোগাযোগ করুন', false)
            ->assertDontSee('Frequently Asked Questions', false)
            ->assertDontSee('Where can I buy Madina products?', false);

        $product = Product::create([
            'sku' => 'BN-DETAIL-1',
            'name' => 'Bangla Detail Product',
            'category' => 'Care',
            'mrp' => '150.00',
            'dealer_price' => '90.00',
            'stock' => 8,
            'active' => true,
        ]);

        $this->onPortal('public')->get('/products/'.$product->id)
            ->assertOk()
            ->assertSee('আমাদের ডিলারদের মাধ্যমে পাওয়া যায়', false)
            ->assertSee('ডিলার / সাব-ডিলার লগইন', false)
            ->assertSee('প্রাপ্যতা', false)
            ->assertSee('পণ্য কোড', false)
            ->assertDontSee('Available through our dealers', false)
            ->assertDontSee('Dealer / Sub-dealer Login', false)
            ->assertDontSee('Availability:', false);

        $this->onPortal('public')->get('/privacy')
            ->assertOk()
            ->assertSee('গোপনীয়তা নীতি', false)
            ->assertSee('ব্যক্তিগত তথ্য বিক্রি করি না', false)
            ->assertDontSee('How Madina Natural Health Care handles information', false);

        $this->onPortal('public')->get('/terms')
            ->assertOk()
            ->assertSee('ব্যবহারের শর্তাবলী', false)
            ->assertSee('অনুমোদিত কোম্পানি চ্যানেল', false)
            ->assertDontSee('Please read these terms before using', false);
    }

    public function test_english_locale_keeps_familiar_public_chrome(): void
    {
        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('Company Notice', false)
            ->assertSee('Dhaka, Bangladesh', false);

        $this->onPortal('public')->get('/products')
            ->assertOk()
            ->assertSee('Our Product Catalogue', false)
            ->assertSee('Find a Dealer', false);
    }

    public function test_bangla_locale_translates_homepage_trust_pillar_cards(): void
    {
        $this->onPortal('public')->from('/')->post('/locale', ['locale' => 'bn'])
            ->assertRedirect();

        $this->onPortal('public')->get('/')
            ->assertOk()
            ->assertSee('১০০% প্রামাণিক ভেষজ উদ্ভিদ', false)
            ->assertSee('শক্তি ও নিরাপত্তার জন্য সনদপ্রাপ্ত বিশুদ্ধ প্রাকৃতিক ইউনানি ফর্মুলেশন।', false)
            ->assertSee('জিএমপি মানসম্মত কোয়ালিটি', false)
            ->assertSee('সারাদেশব্যাপী পার্টনার নেটওয়ার্ক', false)
            ->assertSee('নিবেদিত স্বাস্থ্য সহায়তা', false)
            ->assertDontSee('100% Authentic Botanical Herbs', false)
            ->assertDontSee('GMP Standard Quality', false)
            ->assertDontSee('Nationwide Partner Network', false)
            ->assertDontSee('Dedicated Health Support', false);
    }
}
