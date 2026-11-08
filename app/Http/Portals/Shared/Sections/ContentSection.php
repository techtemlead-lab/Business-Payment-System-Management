<?php

namespace App\Http\Portals\Shared\Sections;

use App\Models\Product;
use App\Models\User;
use App\Support\LocalDigits;
use App\Support\ProductVideo;
use App\Support\SiteCustomization;
use Illuminate\Support\Facades\DB;

/**
 * Published content: policy drafts, partner circulars, public pages and website settings.
 */
final class ContentSection implements WorkspaceSection
{
    public function data(SectionContext $context, array $data): array
    {
        return match ($context->section) {
            'policies' => $this->policies(),
            'circulars' => $this->circulars($context->user),
            'content' => $this->pages(),
            'website' => $this->website($context),
            default => [],
        };
    }

    /**
     * Policy drafts, newest first.
     *
     * @return array<string, mixed>
     */
    private function policies(): array
    {
        return [
            'policies' => DB::table('policy_drafts')->orderByDesc('id')->get(),
        ];
    }

    /**
     * Published notices and circulars for partners.
     *
     * @return array<string, mixed>
     */
    private function circulars(User $user): array
    {
        abort_unless(in_array($user->role, ['dealer', 'sub_dealer'], true), 403);

        return [
            'circulars' => DB::table('content_pages')
                ->where('published', true)
                ->where(function ($query): void {
                    $query->where('slug', 'notice')
                        ->orWhere('slug', 'like', 'circular-%');
                })
                ->orderByDesc('updated_at')
                ->get(['id', 'slug', 'title', 'body', 'updated_at']),
        ];
    }

    /**
     * The editable public pages.
     *
     * @return array<string, mixed>
     */
    private function pages(): array
    {
        return [
            'pages' => DB::table('content_pages')
                ->whereIn('slug', ['home', 'about', 'contact', 'notice', 'circular-training', 'circular-price', 'circular-meeting', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms', 'products', 'dealers'])
                ->orderBy('slug')
                ->get(),
        ];
    }

    /**
     * Website settings, page switches, and the products that can be featured.
     *
     * @return array<string, mixed>
     */
    private function website(SectionContext $context): array
    {
        $request = $context->request;
        $user = $context->user;
        $props = [];
        $props['customization'] = SiteCustomization::raw();
        $props['defaults'] = SiteCustomization::defaults();
        // Built-in Bangla wording, shown as the hint in the editor's Bangla boxes.
        $props['bangla_defaults'] = [
            'copyright' => __('site.copyright', ['year' => LocalDigits::text(date('Y'), 'bn')], 'bn'),
            'notice_empty' => __('site.notice_empty', [], 'bn'),
            'md_message_heading' => __('site.md_message_heading', [], 'bn'),
            'md_message_name' => __('site.md_message_name', [], 'bn'),
            'md_message_title' => __('site.md_message_title', [], 'bn'),
            'md_message_company' => __('site.md_message_company', [], 'bn'),
            'md_message_body' => __('site.md_message_body', [], 'bn'),
        ];
        $enabled = SiteCustomization::enabledPagesMap();
        $props = [...$props, ...ProductVideo::managementData()];
        $props['websitePages'] = collect([
            ['key' => 'home', 'slug' => '/', 'label' => __('portal.website_page_home'), 'toggleable' => false],
            ['key' => 'about', 'slug' => '/about', 'label' => __('portal.website_page_about')],
            ['key' => 'products', 'slug' => '/products', 'label' => __('portal.website_page_products')],
            ['key' => 'dealers', 'slug' => '/dealers', 'label' => __('portal.website_page_dealers')],
            ['key' => 'partnership', 'slug' => '/partnership', 'label' => __('portal.website_page_partnership')],
            ['key' => 'services', 'slug' => '/services', 'label' => __('portal.website_page_services')],
            ['key' => 'quality', 'slug' => '/quality', 'label' => __('portal.website_page_quality')],
            ['key' => 'faq', 'slug' => '/faq', 'label' => __('portal.website_page_faq')],
            ['key' => 'contact', 'slug' => '/contact', 'label' => __('portal.website_page_contact')],
            ['key' => 'privacy', 'slug' => '/privacy', 'label' => __('portal.website_page_privacy')],
            ['key' => 'terms', 'slug' => '/terms', 'label' => __('portal.website_page_terms')],
        ])->map(function (array $page) use ($enabled) {
            $key = $page['key'];
            $toggleable = $page['toggleable'] ?? true;

            return [
                'key' => $key,
                'slug' => $page['slug'],
                'label' => $page['label'],
                'toggleable' => $toggleable,
                'enabled' => $toggleable ? ($enabled[$key] ?? true) : true,
                'flag' => $toggleable ? 'page_enabled_'.$key : null,
            ];
        })->values()->all();
        $revealStock = $user->allows('inventory');
        $revealDealerPrice = $user->allows('pricing') || $user->allows('orders') || $user->allows('finance');
        $websiteProductQuery = Product::query()
            ->where('active', true)
            ->when($request->filled('q'), fn ($q) => $q->where(fn ($match) => $match
                ->where('name', 'ilike', '%'.$request->string('q').'%')
                ->orWhere('sku', 'ilike', '%'.$request->string('q').'%')
                ->orWhere('category', 'ilike', '%'.$request->string('q').'%')))
            ->orderBy('name');
        $websiteProductLimit = 250;
        $props['products_truncated'] = (clone $websiteProductQuery)->count() > $websiteProductLimit;
        $props['products'] = (clone $websiteProductQuery)
            ->limit($websiteProductLimit)
            ->get()
            ->map(fn ($p) => [
                'id' => $p->id,
                'name' => $p->name,
                'sku' => $p->sku,
                'mrp' => $p->mrp,
                ...($revealDealerPrice ? ['dealer_price' => $p->dealer_price] : []),
                'category' => $p->category,
                'featured' => (bool) $p->featured,
                ...($revealStock ? ['stock' => (int) $p->stock] : []),
                'image' => $p->media()['image_url'] ?? '',
            ]);

        return $props;
    }
}
