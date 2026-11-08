<?php

namespace App\Domain\Content;

use App\Support\SiteCustomization;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Owner-edited wording for the public trust pages (verify a dealer, policies, code of
 * conduct, grievance, quality documents, health articles) and the rules for when a page
 * or footer link is shown. Text lives in the website settings next to the other CMS text.
 */
final class PublicTrustContent
{
    /** Pages that are only a heading, a short lead and body text. */
    public const TextPages = [
        'refund-policy' => 'refund',
        'delivery-policy' => 'delivery',
        'code-of-conduct' => 'conduct',
        'partner-exit-policy' => 'exit',
    ];

    /** Every public page this feature adds, in footer order. */
    public const Pages = ['verify-dealer', 'refund-policy', 'delivery-policy', 'code-of-conduct', 'partner-exit-policy', 'grievance', 'quality-documents', 'articles'];

    /** Text the owner can edit; each key has an English box and a Bangla (_bn) box. */
    public const TextKeys = [
        'refund_title', 'refund_lead', 'refund_body',
        'delivery_title', 'delivery_lead', 'delivery_body',
        'conduct_title', 'conduct_lead', 'conduct_body',
        'exit_title', 'exit_lead', 'exit_body',
        'verify_title', 'verify_lead', 'verify_warning',
        'grievance_title', 'grievance_lead', 'grievance_contact_note',
        'quality_documents_title', 'quality_documents_lead',
        'articles_title', 'articles_lead', 'articles_disclaimer',
    ];

    /** Single-language fields, shown only when the owner fills them in. */
    public const IdentityKeys = ['trade_licence', 'tin', 'digital_business_id', 'joint_stock_registration'];

    public const ContactKeys = ['grievance_contact_name', 'grievance_contact_role', 'grievance_contact_phone', 'grievance_contact_email', 'grievance_contact_hours'];

    private const CountsCacheKey = 'public:trust_counts:v1';

    private const CountsCacheTtlSeconds = 300;

    /**
     * Blank values for the website settings. Built-in drafts live in lang/{en,bn}/public_trust.php,
     * so a blank box always means "use the draft" and the identity fields start empty.
     *
     * @return array<string, string>
     */
    public static function defaults(): array
    {
        $defaults = [];
        foreach (self::Pages as $page) {
            $defaults['page_enabled_'.$page] = '1';
        }
        foreach (self::TextKeys as $key) {
            $defaults['trust_'.$key] = '';
            $defaults['trust_'.$key.'_bn'] = '';
        }
        foreach ([...self::IdentityKeys, ...self::ContactKeys] as $key) {
            $defaults['trust_'.$key] = '';
        }

        return $defaults;
    }

    /**
     * The owner's text in the visitor's language. Follows the same rule as the rest of the
     * website: a filled Bangla box wins for Bangla visitors, then an English box the owner
     * wrote, then the built-in draft for that language.
     */
    public static function text(string $key): string
    {
        $all = SiteCustomization::all();
        $bangla = trim((string) ($all['trust_'.$key.'_bn'] ?? ''));
        if (app()->getLocale() === 'bn' && $bangla !== '') {
            return $bangla;
        }
        $custom = trim((string) ($all['trust_'.$key] ?? ''));

        return $custom !== '' ? $custom : (string) __('public_trust.'.$key);
    }

    /** A plain owner field (identity number, contact detail), trimmed; empty when not filled in. */
    public static function field(string $key): string
    {
        return trim((string) (SiteCustomization::all()['trust_'.$key] ?? ''));
    }

    public static function isEnabled(string $page): bool
    {
        return filter_var(SiteCustomization::all()['page_enabled_'.$page] ?? '1', FILTER_VALIDATE_BOOLEAN);
    }

    /** True when the page is switched on and has something to show. */
    public static function isVisible(string $page): bool
    {
        if (! self::isEnabled($page)) {
            return false;
        }

        if (isset(self::TextPages[$page])) {
            return self::text(self::TextPages[$page].'_body') !== '';
        }

        return match ($page) {
            'quality-documents' => self::publishedCounts()['documents'] > 0,
            'articles' => self::publishedCounts()['articles'] > 0,
            default => true,
        };
    }

    /**
     * Footer links for the pages that are currently visible.
     *
     * @return list<array{url: string, label: string}>
     */
    public static function footerLinks(): array
    {
        $links = [];
        foreach (self::Pages as $page) {
            if (self::isVisible($page)) {
                $links[] = ['url' => '/'.$page, 'label' => (string) __('public_trust.footer_'.str_replace('-', '_', $page))];
            }
        }

        return $links;
    }

    /**
     * The registration numbers the owner has filled in.
     *
     * @return list<array{label: string, value: string}>
     */
    public static function businessIdentity(): array
    {
        $items = [];
        foreach (self::IdentityKeys as $key) {
            $value = self::field($key);
            if ($value !== '') {
                $items[] = ['label' => (string) __('public_trust.identity_'.$key), 'value' => $value];
            }
        }

        return $items;
    }

    /**
     * How many documents and articles are public. Cached because the footer needs it on every page.
     *
     * @return array{documents: int, articles: int}
     */
    public static function publishedCounts(): array
    {
        $cached = Cache::memo()->get(self::CountsCacheKey);
        if (is_array($cached) && isset($cached['documents'], $cached['articles'])) {
            return ['documents' => (int) $cached['documents'], 'articles' => (int) $cached['articles']];
        }

        try {
            $row = DB::selectOne('SELECT (SELECT COUNT(*) FROM quality_documents WHERE is_published = true) AS documents, (SELECT COUNT(*) FROM health_articles WHERE published_at IS NOT NULL) AS articles');
        } catch (\Throwable) {
            // During a deploy the code can arrive before the tables do; the footer must still render.
            return ['documents' => 0, 'articles' => 0];
        }

        $counts = ['documents' => (int) $row->documents, 'articles' => (int) $row->articles];
        Cache::memo()->put(self::CountsCacheKey, $counts, self::CountsCacheTtlSeconds);

        return $counts;
    }

    public static function forgetCounts(): void
    {
        Cache::memo()->forget(self::CountsCacheKey);
    }
}
