<?php

use App\Support\SiteCustomization;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Website text saved earlier can still hold another company's name, email, factory
 * address and phone, and wording taken from its product leaflet. A saved top-level
 * field that mentions them is dropped, so today's clean default shows until the
 * owner writes their own.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const Markers = [
        'holistic nutracare',
        'holisticnutracare',
        'holistic wellness',
        'নিউট্রাকেয়ার',
        'moshorikhula',
        'মশরীখোলা',
        'মশুরীখোলা',
        '01727612379',
        'free from adulteration',
        'every home in bangladesh',
        'ভেজালমুক্ত',
        'প্রতিটি ঘরে',
    ];

    public function up(): void
    {
        $this->cleanWebsiteContent();

        DB::table('products')
            ->where('description', 'like', '%/ Holistic Nutracare%')
            ->update([
                'description' => DB::raw("replace(description, ' / Holistic Nutracare', '')"),
                'updated_at' => now(),
            ]);
    }

    /**
     * The removed text belonged to another company, so there is nothing to restore.
     */
    public function down(): void {}

    private function cleanWebsiteContent(): void
    {
        $page = DB::table('content_pages')->where('slug', 'frontend_customization')->first();
        $content = $page ? json_decode((string) $page->body, true) : null;
        if (! is_array($content)) {
            return;
        }

        $cleaned = [];
        foreach ($content as $key => $value) {
            if (is_string($value) && $this->mentionsOtherCompany($value)) {
                continue;
            }
            $cleaned[$key] = is_array($value) ? $this->cleanNested($value) : $value;
        }

        if ($cleaned === $content) {
            return;
        }

        DB::table('content_pages')->where('id', $page->id)->update([
            'body' => json_encode($cleaned, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'updated_at' => now(),
        ]);
        SiteCustomization::forgetCache();
    }

    /**
     * @param  array<array-key, mixed>  $items
     * @return array<array-key, mixed>
     */
    private function cleanNested(array $items): array
    {
        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $items[$key] = $this->cleanNested($item);
            } elseif (is_string($item) && $this->mentionsOtherCompany($item)) {
                $items[$key] = match ($key) {
                    'alt' => 'Madina Natural Health Care',
                    'subtitle' => 'Herbal and nutrition products for everyday care.',
                    default => '',
                };
            }
        }

        return $items;
    }

    private function mentionsOtherCompany(string $text): bool
    {
        $lower = mb_strtolower($text);
        foreach (self::Markers as $marker) {
            if (str_contains($lower, $marker)) {
                return true;
            }
        }

        return false;
    }
};
