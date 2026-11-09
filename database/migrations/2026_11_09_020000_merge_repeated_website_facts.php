<?php

use App\Support\SiteCustomization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The website used to keep the same fact in several boxes: the phone in the footer and
 * on the Contact page, the WhatsApp link twice, and so on. Each fact now has one home.
 * An edit the owner made in any box is moved to that home, then the repeat is removed.
 * The lists are frozen here so this keeps meaning the same thing later.
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const Repeats = [
        'top_bar_text' => 'contact_company_title',
        'footer_about_title' => 'contact_company_title',
        'footer_support_title' => 'contact_phone',
        'footer_email' => 'contact_email',
        'footer_address' => 'office_address',
        'legal_meta_location' => 'footer_location',
        'whatsapp_link' => 'social_whatsapp',
    ];

    /** Factory wording: a box holding one of these was never edited by the owner. */
    private const Factory = [
        'contact_company_title' => ['Madina Natural Health Care', 'MADINA NATURAL HEALTH CARE', 'মদিনা ন্যাচারাল হেলথ কেয়ার'],
        'contact_phone' => ['+8801730532966', '01730532966'],
        'contact_email' => [],
        'office_address' => ['Thana Road, Pirganj 5470, Rangpur, Bangladesh', 'Thana Road, Pirganj 5470, Rangpur', 'থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর, বাংলাদেশ', 'থানা রোড, পীরগঞ্জ ৫৪৭০, রংপুর'],
        'footer_location' => ['Pirganj, Rangpur, Bangladesh', 'পীরগঞ্জ, রংপুর, বাংলাদেশ', 'Dhaka, Bangladesh', 'ঢাকা, বাংলাদেশ'],
        'social_whatsapp' => ['https://wa.me/8801730532966'],
    ];

    private const SecondPhone = '01730532967';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(?)', [81416002]);

            $page = DB::table('content_pages')->where('slug', 'frontend_customization')->lockForUpdate()->first();
            $content = $page ? json_decode((string) $page->body, true) : null;
            if (! is_array($content)) {
                return;
            }

            $merged = $this->withSecondPhoneKept($this->withFactsInOnePlace($content));
            if ($merged === $content) {
                return;
            }

            DB::table('content_pages')->where('id', $page->id)->update([
                'body' => json_encode($merged, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }, 3);

        SiteCustomization::forgetCache();
    }

    /** The repeated boxes are gone and their old values cannot be told apart again. */
    public function down(): void {}

    /**
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function withFactsInOnePlace(array $content): array
    {
        foreach (self::Repeats as $repeat => $fact) {
            $repeated = $this->text($content, $repeat);
            if ($this->ownerWrote($fact, $repeated) && ! $this->ownerWrote($fact, $this->text($content, $fact))) {
                $content[$fact] = $repeated;
            }
            unset($content[$repeat]);
        }
        unset($content['footer_support_link']);

        return $content;
    }

    /**
     * The second phone used to be typed as a sentence on the Contact page, and the footer
     * box often held the first phone again. The box now always holds the second number.
     *
     * @param  array<string, mixed>  $content
     * @return array<string, mixed>
     */
    private function withSecondPhoneKept(array $content): array
    {
        $digits = static fn (string $phone): string => ltrim((string) preg_replace('/\D/', '', $phone), '0');
        $main = $digits($this->text($content, 'contact_phone') ?: '+8801730532966');
        $second = $this->text($content, 'footer_phone');
        if ($second === '' || str_ends_with($main, $digits($second))) {
            $content['footer_phone'] = self::SecondPhone;
        }

        return $content;
    }

    /** @param array<string, mixed> $content */
    private function text(array $content, string $key): string
    {
        $value = $content[$key] ?? '';

        return is_scalar($value) ? trim((string) $value) : '';
    }

    private function ownerWrote(string $fact, string $value): bool
    {
        return $value !== ''
            && ! in_array($value, self::Factory[$fact], true)
            && ! str_contains(mb_strtolower($value), 'holistic');
    }
};
