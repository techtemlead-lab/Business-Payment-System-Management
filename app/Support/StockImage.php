<?php

namespace App\Support;

/**
 * Neutral default pictures for image slots the owner has not filled yet.
 *
 * They are picked when the page renders, so nothing is written to the database and
 * anything the owner uploads or sets in the CMS takes over straight away.
 */
final class StockImage
{
    public const Width = 1000;

    public const Height = 750;

    private const BasePath = '/branding/stock/';

    private const DefaultImage = 'brand';

    /**
     * Checked in order against the lower-cased category name; the first match wins.
     *
     * @var array<string, list<string>>
     */
    private const CategoryKeywords = [
        'honey' => ['syrup', 'honey', 'সিরাপ', 'মধু'],
        'black-seed' => ['oil', 'black seed', 'kalonji', 'তেল', 'কালোজিরা'],
        'dates' => ['superfood', 'super food', 'dates', 'সুপারফুড', 'খেজুর'],
        'aloe' => ['cosme', 'skin', 'beauty', 'aloe', 'কসমে', 'ত্বক', 'অ্যালোভেরা'],
        'herbs' => ['ayurved', 'herb', 'unani', 'আয়ুর্বেদ', 'হার্ব', 'হারবাল', 'ভেষজ', 'ইউনানি'],
        'leaves' => ['botanical', 'wellness', 'daily', 'essential', 'বোটানিক', 'ওয়েলনেস'],
    ];

    /**
     * @var array<string, string>
     */
    private const SectionImages = [
        'article' => 'herbs',
    ];

    /**
     * The default picture for a product category. Unknown categories get the brand illustration.
     *
     * @return array{url: string, width: int, height: int, alt: string, caption: string}
     */
    public static function forCategory(?string $category, ?string $productName = null): array
    {
        $image = self::imageForCategory((string) $category);
        $subject = self::subject($image);
        $name = trim((string) $productName);

        return self::payload($image, $name !== ''
            ? __('stock_images.product_alt', ['name' => $name, 'subject' => $subject])
            : __('stock_images.section_alt', ['subject' => $subject]));
    }

    /**
     * The default picture for a page section such as "article".
     *
     * @return array{url: string, width: int, height: int, alt: string, caption: string}
     */
    public static function forSection(string $section): array
    {
        $image = self::SectionImages[$section] ?? self::DefaultImage;

        return self::payload($image, __('stock_images.section_alt', ['subject' => self::subject($image)]));
    }

    /**
     * @return list<string>
     */
    public static function allImageNames(): array
    {
        return [...array_keys(self::CategoryKeywords), self::DefaultImage];
    }

    private static function imageForCategory(string $category): string
    {
        $needle = mb_strtolower(trim($category));
        foreach (self::CategoryKeywords as $image => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($needle, $keyword)) {
                    return $image;
                }
            }
        }

        return self::DefaultImage;
    }

    private static function subject(string $image): string
    {
        return (string) __('stock_images.subject_'.str_replace('-', '_', $image));
    }

    /**
     * @return array{url: string, width: int, height: int, alt: string, caption: string}
     */
    private static function payload(string $image, string $alt): array
    {
        return [
            'url' => self::BasePath.$image.'.webp',
            'width' => self::Width,
            'height' => self::Height,
            'alt' => $alt,
            'caption' => (string) __('stock_images.caption'),
        ];
    }
}
