<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PartnerIdCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dealer_can_download_partner_id_card_pdf(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-ID-1',
            'refer_code' => 'MNHC-ID-1',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/id-card/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Madina-partner-id-MNHC-ID-1.pdf');
    }

    public function test_bangla_dealer_id_card_and_join_poster_pdf_use_partner_locale_strings(): void
    {
        $this->assertSame('ডিলার কোড', __('portal.pdf_id_card_dealer_code', [], 'bn'));
        $this->assertSame('QR কোড স্ক্যান করুন অথবা জয়েন লিংক খুলে এই রেফার কোড দিয়ে আবেদন করুন।', __('portal.pdf_join_poster_lead', [], 'bn'));

        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-ID-BN',
            'refer_code' => 'MNHC-ID-BN',
            'locale' => 'bn',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/id-card/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/join-poster/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_staff_cannot_download_partner_id_card(): void
    {
        $staff = User::factory()->create(['role' => 'finance', 'status' => 'active']);

        $this->actingAs($staff)->onPortal('manage')
            ->get('/id-card/pdf')
            ->assertNotFound();
    }

    public function test_places_path_redirects_to_place_codes(): void
    {
        $manager = User::factory()->create(['role' => 'account_manager', 'status' => 'active']);

        $this->actingAs($manager)->onPortal('manage')
            ->get('/places')
            ->assertRedirect('/place-codes');
    }

    public function test_dealer_referral_qr_svg_is_scannable_matrix(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'refer_code' => 'MNHC-QR-1',
        ]);

        $response = $this->actingAs($dealer)->onPortal('dealer')
            ->get('/referral-qr.svg')
            ->assertOk()
            ->assertHeader('content-type', 'image/svg+xml; charset=UTF-8');

        $svg = (string) $response->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->assertStringContainsString('<rect', $svg);
        $this->assertGreaterThan(1000, strlen($svg));
    }

    public function test_dealer_can_download_join_poster_pdf(): void
    {
        $dealer = User::factory()->create([
            'role' => 'dealer',
            'status' => 'active',
            'dealer_code' => 'MNHC-POSTER-1',
            'refer_code' => 'MNHC-POSTER-1',
            'division' => 'Dhaka',
            'district' => 'Dhaka',
        ]);

        $this->actingAs($dealer)->onPortal('dealer')
            ->get('/join-poster/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('content-disposition', 'attachment; filename=Madina-join-poster-MNHC-POSTER-1.pdf');
    }

    /** @return list<array{0: string}> */
    public static function cardLanguages(): array
    {
        return [['en'], ['bn']];
    }

    #[DataProvider('cardLanguages')]
    public function test_the_id_card_fits_on_one_card_even_with_a_long_name_and_place(string $locale): void
    {
        $dealer = User::factory()->create([
            'role' => 'sub_dealer',
            'status' => 'active',
            'locale' => $locale,
            'name' => 'QA Test Partner Long Name Rahman',
            'dealer_code' => 'MNHC-LONG-1',
            'refer_code' => 'MNHC-LONG-1',
            'phone' => '01700000111',
            'division' => 'Dhaka',
            'district' => 'Gazipur',
            'profile' => ['shop_name' => 'QA Test Pharmacy', 'upazila' => 'Sreepur'],
        ]);

        $response = $this->actingAs($dealer)->onPortal('subdealer')->get('/id-card/pdf')->assertOk();
        $pdf = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertSame(1, preg_match_all('#/Type /Page\b(?!s)#', $pdf));
    }

    #[DataProvider('cardLanguages')]
    public function test_the_id_card_is_standard_card_size_and_a_very_long_name_stays_on_one_page(string $locale): void
    {
        $dealer = User::factory()->create([
            'role' => 'sub_dealer',
            'status' => 'active',
            'locale' => $locale,
            'name' => substr(str_repeat('Very Long Partner Name ', 7), 0, 150),
            'dealer_code' => 'MNHC-100001-000123',
            'refer_code' => 'MNHC-100001-CUSTOM-REFER-CODE-1',
            'phone' => '01700000112',
            'division' => 'Mymensingh',
            'district' => 'Chapainawabganj',
            'profile' => ['shop_name' => substr(str_repeat('Very Long Pharmacy Name ', 7), 0, 150), 'upazila' => substr(str_repeat('Upazila Name ', 12), 0, 150)],
        ]);

        $response = $this->actingAs($dealer)->onPortal('subdealer')->get('/id-card/pdf')->assertOk();
        $pdf = (string) file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertSame(1, preg_match_all('#/Type /Page\b(?!s)#', $pdf));
        $this->assertSame(1, preg_match('#/MediaBox \[0(?:\.000)? 0(?:\.000)? ([0-9.]+) ([0-9.]+)\]#', $pdf, $box));
        $this->assertEqualsWithDelta(85.6, (float) $box[1] * 25.4 / 72, 0.1, 'CR80 width in millimetres.');
        $this->assertEqualsWithDelta(54.0, (float) $box[2] * 25.4 / 72, 0.1, 'CR80 height in millimetres.');
    }
}
