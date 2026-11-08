<?php

namespace App\Domain\Catalog;

use App\Domain\Content\TestimonialLabels;
use App\Models\Product;
use App\Models\ProductConcern;
use App\Models\SiteFeedback;

/**
 * What the owner has entered about a product for the public website.
 *
 * Every fact starts blank and the website shows a fact only when it is filled in,
 * so nothing here may ever supply a default claim, registration number or ingredient.
 */
final class ProductFacts
{
    /**
     * Facts written in Bangla and English, with the longest text accepted.
     *
     * @var array<string, array{max_length: int, multiline: bool}>
     */
    public const BilingualFields = [
        'manufacturer' => ['max_length' => 200, 'multiline' => false],
        'product_system' => ['max_length' => 120, 'multiline' => false],
        'pack_size' => ['max_length' => 120, 'multiline' => false],
        'ingredients' => ['max_length' => 3000, 'multiline' => true],
        'dose' => ['max_length' => 2000, 'multiline' => true],
        'warnings' => ['max_length' => 2000, 'multiline' => true],
        'storage' => ['max_length' => 1000, 'multiline' => true],
        'quality_points' => ['max_length' => 2000, 'multiline' => true],
    ];

    public const RegistrationNumberMaxLength = 120;

    /** How many approved reviews a product page shows. */
    public const ReviewsShown = 20;

    /**
     * Product table columns holding these facts.
     *
     * @return list<string>
     */
    public static function columns(): array
    {
        $columns = ['registration_number'];
        foreach (array_keys(self::BilingualFields) as $field) {
            $columns[] = $field.'_bn';
            $columns[] = $field.'_en';
        }

        return $columns;
    }

    /**
     * Form fields that need the catalog permission to change.
     *
     * @return list<string>
     */
    public static function requestFields(): array
    {
        return [...self::columns(), 'concern_ids', 'sync_concerns'];
    }

    /**
     * Validation for the product editor. Only people who can edit the catalog may send these.
     *
     * @return array<string, string>
     */
    public static function validationRules(bool $canEditCatalog): array
    {
        if (! $canEditCatalog) {
            return array_fill_keys([...self::requestFields(), 'concern_ids.*'], 'prohibited');
        }

        $rules = ['registration_number' => 'sometimes|nullable|string|max:'.self::RegistrationNumberMaxLength];
        foreach (self::BilingualFields as $field => $settings) {
            $rules[$field.'_bn'] = 'sometimes|nullable|string|max:'.$settings['max_length'];
            $rules[$field.'_en'] = 'sometimes|nullable|string|max:'.$settings['max_length'];
        }

        return $rules + [
            'concern_ids' => 'sometimes|array|max:30',
            'concern_ids.*' => 'integer|exists:product_concerns,id',
            'sync_concerns' => 'sometimes|boolean',
        ];
    }

    /**
     * Saved values for the product editor; blank for a new product.
     *
     * @return array<string, string>
     */
    public static function forEditor(?Product $product): array
    {
        $values = [];
        foreach (self::columns() as $column) {
            $values[$column] = (string) ($product?->getAttribute($column) ?? '');
        }

        return $values;
    }

    /**
     * The fields the editor draws, in the order they appear.
     *
     * @return list<array{name: string, max_length: int, multiline: bool}>
     */
    public static function editorFields(): array
    {
        $fields = [];
        foreach (self::BilingualFields as $name => $settings) {
            $fields[] = ['name' => $name, ...$settings];
        }

        return $fields;
    }

    /**
     * Facts for the visitor's language. A blank fact is null, and a fact filled in only
     * one language is shown in that language rather than hidden.
     *
     * @return array{manufacturer: ?string, product_system: ?string, registration_number: ?string, pack_size: ?string, ingredients: ?string, dose: ?string, warnings: ?string, storage: ?string, quality_points: list<string>}
     */
    public static function forPublic(Product $product): array
    {
        return [
            'manufacturer' => self::inVisitorLanguage($product, 'manufacturer'),
            'product_system' => self::inVisitorLanguage($product, 'product_system'),
            'registration_number' => self::textOrNull($product->getAttribute('registration_number')),
            'pack_size' => self::inVisitorLanguage($product, 'pack_size'),
            'ingredients' => self::inVisitorLanguage($product, 'ingredients'),
            'dose' => self::inVisitorLanguage($product, 'dose'),
            'warnings' => self::inVisitorLanguage($product, 'warnings'),
            'storage' => self::inVisitorLanguage($product, 'storage'),
            'quality_points' => self::lines(self::inVisitorLanguage($product, 'quality_points')),
        ];
    }

    /**
     * Concern tags on this product, named for the visitor.
     *
     * @return list<array{id: int, name: string}>
     */
    public static function concernTags(Product $product): array
    {
        return $product->concerns()
            ->orderBy('name_en')
            ->get()
            ->map(fn (ProductConcern $concern): array => [
                'id' => (int) $concern->id,
                'name' => ProductConcern::nameForVisitor((string) $concern->name_bn, (string) $concern->name_en),
            ])
            ->values()
            ->all();
    }

    /**
     * Reviews of this product the owner has approved, newest first.
     *
     * @return list<array{name: string, location: string, rating: int, message: string}>
     */
    public static function approvedReviews(Product $product): array
    {
        return SiteFeedback::query()
            ->where('product_id', $product->id)
            ->where('status', 'approved')
            ->orderByDesc('moderated_at')
            ->orderByDesc('id')
            ->limit(self::ReviewsShown)
            ->get()
            ->map(fn (SiteFeedback $review): array => [
                'name' => (string) $review->name,
                'location' => TestimonialLabels::location($review->location),
                'rating' => max(1, min(5, (int) $review->rating)),
                'message' => (string) $review->message,
            ])
            ->values()
            ->all();
    }

    private static function inVisitorLanguage(Product $product, string $field): ?string
    {
        $languages = app()->getLocale() === 'bn' ? ['bn', 'en'] : ['en', 'bn'];
        foreach ($languages as $language) {
            $text = self::textOrNull($product->getAttribute($field.'_'.$language));
            if ($text !== null) {
                return $text;
            }
        }

        return null;
    }

    private static function textOrNull(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return list<string>
     */
    private static function lines(?string $text): array
    {
        if ($text === null) {
            return [];
        }
        $lines = preg_split('/\R/u', $text) ?: [];

        return array_values(array_filter(array_map('trim', $lines), fn (string $line): bool => $line !== ''));
    }
}
