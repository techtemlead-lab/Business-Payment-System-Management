<?php

namespace App\Domain\Content;

/**
 * Words shown next to a customer or partner review.
 *
 * When the person left their role or place empty, the review shows a word
 * for who sent it (customer, dealer, sub-dealer) and "Bangladesh", in the
 * visitor's language. Old reviews were saved with English filler text; that
 * text is treated as empty too.
 */
final class TestimonialLabels
{
    /** Validation rule that refuses HTML tags in text people type into review forms. */
    public const NoHtmlRule = 'not_regex:/<\s*\/?\s*[a-z!?][^>]*>/i';

    /** English filler that older reviews stored when the role was left empty. */
    private const LegacyRoles = ['Verified Client', 'Authorized Dealer Partner', 'Sub-Dealer Partner'];

    /** English filler that older reviews stored when the place was left empty. */
    private const LegacyLocations = ['Bangladesh'];

    public static function role(?string $role, ?string $source): string
    {
        $role = trim((string) $role);
        if ($role !== '' && ! in_array($role, self::LegacyRoles, true)) {
            return $role;
        }

        return (string) match ($source) {
            'dealer' => __('site.testimonial_role_dealer'),
            'sub_dealer' => __('site.testimonial_role_sub_dealer'),
            default => __('site.testimonial_role_customer'),
        };
    }

    public static function location(?string $location): string
    {
        $location = trim((string) $location);
        if ($location !== '' && ! in_array($location, self::LegacyLocations, true)) {
            return $location;
        }

        return (string) __('site.testimonial_location_default');
    }
}
