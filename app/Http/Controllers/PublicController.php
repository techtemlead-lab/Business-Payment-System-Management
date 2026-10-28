<?php

namespace App\Http\Controllers;

use App\Domain\Audience\PublicPartnerApplication;
use App\Domain\Integrations\MapsPresentation;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\Product;
use App\Models\User;
use App\Support\PortalHost;
use App\Support\SiteCustomization;
use App\Support\SocialShareMeta;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicController extends Controller
{
    public function index(Request $request, string $page = 'home'): View
    {
        abort_unless(in_array($page, ['home', 'products', 'dealers', 'about', 'contact', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms', 'notice', 'circular-training', 'circular-price', 'circular-meeting'], true), 404);
        abort_unless(SiteCustomization::isPageEnabled($page), 404);
        $request->validate(['q' => 'nullable|string|max:100', 'district' => 'nullable|string|max:80',
            'division' => 'nullable|string|max:80', 'category' => 'nullable|string|max:100',
            'page' => 'nullable|integer|min:1', 'sort' => 'nullable|in:name,price_asc,price_desc', 'in_stock' => 'nullable|in:0,1',
            'min_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/', 'max_price' => 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/',
            'lat' => 'nullable|numeric|between:-90,90', 'lng' => 'nullable|numeric|between:-180,180']);
        if ($page === 'products') {
            return $this->catalog($request);
        }

        $needsCatalog = in_array($page, ['home'], true);
        $needsDealers = $page === 'dealers';
        $needsMaps = in_array($page, ['contact', 'about', 'services'], true);

        $products = $needsCatalog
            ? Product::where('active', true)
                ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
                ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
                ->orderBy('name')->paginate(4, ['id', 'name', 'sku', 'category', 'description', 'mrp', 'unit', 'tone'])->withQueryString()
            : new LengthAwarePaginator([], 0, 12);

        $dealers = new LengthAwarePaginator([], 0, 12);
        $districts = collect();
        $divisions = collect();
        if ($needsDealers) {
            [$dealers, $districts, $divisions] = $this->dealerDirectory($request);
        }

        $featuredProducts = $needsCatalog ? $this->cachedFeaturedProducts() : [];
        $featuredCategoryTabs = $needsCatalog ? SiteCustomization::getFeaturedCategoryTabs($featuredProducts) : [];
        $featuredItems = $featuredProducts;

        $siteCustomization = SiteCustomization::all();
        $maps = null;
        if ($needsMaps) {
            $officeAddress = (string) ($siteCustomization['office_address'] ?? 'Thana Road, Pirganj 5470, Rangpur, Bangladesh');
            $maps = app(MapsPresentation::class)->forAddress($officeAddress);
        }

        $contentPages = $this->cachedContentPages();
        $pageContent = $contentPages[$page] ?? null;
        $customTitle = filled($pageContent->title ?? null) ? ((string) $pageContent->title.' · '.(string) config('app.name', 'Madina Natural Health Care')) : null;
        $customDesc = filled($pageContent->body ?? null) ? mb_substr(trim(strip_tags((string) $pageContent->body)), 0, 280) : null;

        $social = $page === 'home'
            ? SocialShareMeta::homepage()
            : SocialShareMeta::page($page, $customTitle, $customDesc);

        return view($page === 'home' ? 'reference.home' : 'reference.information', compact('page', 'products', 'dealers', 'districts', 'divisions', 'featuredItems', 'featuredProducts', 'featuredCategoryTabs') + [
            'content' => $pageContent,
            'notice' => $contentPages['notice'] ?? null,
            // Home does not render catalog category filters; skip the cached pluck there.
            'categories' => collect(),
            'maps' => $maps,
            'social' => $social,
        ])->withHeaders([
            'Cache-Control' => 'private, no-cache',
            'X-Public-Render' => 'lean',
        ]);
    }

    public function product(Request $request, Product $product): View
    {
        abort_unless(SiteCustomization::isPageEnabled('products'), 404);
        abort_unless($product->active, 404);

        $item = $this->publicProduct($product);
        $partner = $this->resolveSharePartner($request);
        $social = SocialShareMeta::product($item, $partner);

        return view('reference.product', [
            'item' => $item,
            'social' => $social,
            'sharePartner' => $partner,
        ]);
    }

    private function publicProduct(Product $p): array
    {
        return [
            ...$p->only(['id', 'name', 'sku', 'category', 'description', 'unit']),
            'price' => $p->mrp,
            'mrp' => $p->mrp,
            'stock_status' => $p->stockStatus(),
            'url' => PortalHost::url('public', '/products/'.$p->id),
            'image_path' => $p->media()['image_url'],
            ...$p->media(),
            'preview' => false,
        ];
    }

    private function catalog(Request $r): View
    {
        $query = Product::where('active', true)
            ->when($r->filled('q'), fn ($query) => $query->where('name', 'ilike', '%'.$r->string('q').'%'))
            ->when($r->filled('category'), fn ($query) => $query->where('category', 'ilike', trim((string) $r->string('category'))))
            ->when($r->boolean('in_stock'), fn ($query) => $query->where('stock', '>', 0))
            ->when($r->filled('min_price'), fn ($query) => $query->where('mrp', '>=', (string) $r->string('min_price')))
            ->when($r->filled('max_price'), fn ($query) => $query->where('mrp', '<=', (string) $r->string('max_price')));
        match ($r->string('sort', 'name')->toString()) {
            'price_desc' => $query->orderByDesc('mrp'),
            'price_asc' => $query->orderBy('mrp'),
            default => $query->orderBy('name'),
        };
        $catalog = $query->orderBy('id')->paginate(12)->withQueryString()
            ->through(fn (Product $product) => $this->publicProduct($product));
        $catalogCategories = $this->cachedProductCategories();
        $social = SocialShareMeta::page('products', __('site.products').' · '.config('app.name'), __('site.meta_description'));

        return view('reference.catalog', compact('catalog', 'catalogCategories', 'social'));
    }

    /**
     * @return array{0: LengthAwarePaginator, 1: Collection<int, string>, 2: Collection<int, string>}
     */
    private function dealerDirectory(Request $request): array
    {
        $directory = User::where('directory_visible', true)->where('status', 'active')->where('role', 'dealer');
        $dealerCols = ['name', 'dealer_code', 'phone', 'division', 'district', 'latitude', 'longitude', 'profile'];
        if ($request->filled(['lat', 'lng'])) {
            $userLat = (float) $request->input('lat');
            $userLng = (float) $request->input('lng');
            $allDealers = (clone $directory)
                ->when($request->filled('division'), fn ($query) => $query->where('division', $request->string('division')))
                ->when($request->filled('district'), fn ($query) => $query->where('district', $request->string('district')))
                ->get($dealerCols)
                ->map(function ($dealer) use ($userLat, $userLng) {
                    if ($dealer->latitude !== null && $dealer->longitude !== null) {
                        $lat1 = deg2rad($userLat);
                        $lon1 = deg2rad($userLng);
                        $lat2 = deg2rad((float) $dealer->latitude);
                        $lon2 = deg2rad((float) $dealer->longitude);
                        $dLat = $lat2 - $lat1;
                        $dLon = $lon2 - $lon1;
                        $a = sin($dLat / 2) ** 2 + cos($lat1) * cos($lat2) * sin($dLon / 2) ** 2;
                        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                        $dealer->distance_km = round(6371 * $c, 1);
                    } else {
                        $dealer->distance_km = null;
                    }

                    return $dealer;
                })->sort(function ($a, $b) {
                    if ($a->distance_km === null && $b->distance_km === null) {
                        return strcmp($a->name, $b->name);
                    }
                    if ($a->distance_km === null) {
                        return 1;
                    }
                    if ($b->distance_km === null) {
                        return -1;
                    }

                    return $a->distance_km <=> $b->distance_km;
                })->values();
            $pageNumber = $request->integer('page', 1);
            $dealers = new LengthAwarePaginator($allDealers->slice(($pageNumber - 1) * 12, 12)->values(), $allDealers->count(), 12, $pageNumber, ['path' => '/dealers', 'query' => $request->query()]);
        } else {
            $dealers = (clone $directory)
                ->when($request->filled('division'), fn ($query) => $query->where('division', $request->string('division')))
                ->when($request->filled('district'), fn ($query) => $query->where('district', $request->string('district')))
                ->orderBy('name')->paginate(12, $dealerCols)->withQueryString();
        }
        $districts = (clone $directory)->whereNotNull('district')->orderBy('district')->distinct()->pluck('district');
        $divisions = (clone $directory)->whereNotNull('division')->orderBy('division')->distinct()->pluck('division');

        return [$dealers, $districts, $divisions];
    }

    /** @return array<string, object|null> */
    private function cachedContentPages(): array
    {
        $cached = $this->rememberArray('public:content_pages:v2', 300, function (): array {
            $rows = DB::table('content_pages')->where('published', true)->get(['slug', 'title', 'body', 'published']);
            $map = [];
            foreach ($rows as $row) {
                $map[(string) $row->slug] = [
                    'slug' => (string) $row->slug,
                    'title' => $row->title !== null ? (string) $row->title : null,
                    'body' => $row->body !== null ? (string) $row->body : null,
                    'published' => (bool) $row->published,
                ];
            }

            return $map;
        });

        $pages = [];
        foreach ($cached as $slug => $row) {
            $pages[$slug] = is_array($row) ? (object) $row : null;
        }

        return $pages;
    }

    /** @return Collection<int, string> */
    private function cachedProductCategories(): Collection
    {
        // Persist a plain string list only — Support/Eloquent Collections unserialize as
        // __PHP_Incomplete_Class under the database cache driver and 500 public pages.
        $categories = $this->rememberArray('public:product_categories:v2', 300, function (): array {
            return Product::query()
                ->where('active', true)
                ->whereNotNull('category')
                ->orderBy('category')
                ->distinct()
                ->pluck('category')
                ->map(fn (mixed $category): string => (string) $category)
                ->values()
                ->all();
        });

        return collect($categories)->values();
    }

    /** @return list<array<string, mixed>> */
    private function cachedFeaturedProducts(): array
    {
        return $this->rememberArray('public:featured_products:v2', 120, function (): array {
            return SiteCustomization::getFeaturedProducts();
        });
    }

    /**
     * Cache only JSON-safe arrays. Discard poisoned values (e.g. unserialized Collections).
     *
     * @param  callable(): array<mixed>  $callback
     * @return array<mixed>
     */
    private function rememberArray(string $key, int $ttlSeconds, callable $callback): array
    {
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        if ($cached !== null) {
            Cache::forget($key);
        }

        $fresh = $callback();
        Cache::put($key, $fresh, $ttlSeconds);

        return $fresh;
    }

    public function enquiry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'topic' => 'required|in:dealer_enquiry,partnership_enquiry,product_enquiry,support,complaint,Dealer enquiry,Partnership enquiry,Product enquiry,Support,Complaint',
            'referred_by' => 'nullable|string|max:40',
            'message' => 'required|string|min:10|max:2000',
            'website' => 'nullable|max:0',
        ]);
        unset($data['website']);
        $data['topic'] = match ($data['topic']) {
            'Dealer enquiry', 'dealer_enquiry' => 'dealer_enquiry',
            'Partnership enquiry', 'partnership_enquiry' => 'partnership_enquiry',
            'Product enquiry', 'product_enquiry' => 'product_enquiry',
            'Support', 'support' => 'support',
            'Complaint', 'complaint' => 'complaint',
            default => strtolower((string) $data['topic']),
        };
        $referredBy = strtoupper(trim((string) ($data['referred_by'] ?? '')));
        $data['referred_by'] = $referredBy !== '' ? $referredBy : null;
        $locale = app()->getLocale();
        DB::table('enquiries')->insert([
            ...$data,
            'status' => 'new',
            'locale' => $locale,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $topicLabel = match ($data['topic']) {
            'dealer_enquiry' => __('portal.enquiry_topic_dealer', [], $locale),
            'partnership_enquiry' => __('portal.enquiry_topic_partnership', [], $locale),
            'product_enquiry' => __('portal.enquiry_topic_product', [], $locale),
            'support' => __('portal.support_topic_support', [], $locale),
            'complaint' => __('portal.support_topic_complaint', [], $locale),
            default => $data['topic'],
        };
        app(PartnerEventSms::class)->sendToPhone(
            (string) $data['phone'],
            'enquiry.public_received',
            __('sms.public_enquiry_received', ['topic' => $topicLabel], $locale),
        );

        return back()->with('success', __('portal.flash_enquiry_received'));
    }

    public function partnerLogin(): View
    {
        return view('reference.partner-login');
    }

    public function join(string $code): View|RedirectResponse
    {
        $normalized = strtoupper(trim($code));
        abort_if($normalized === '' || strlen($normalized) > 40, 404);

        $partner = User::query()
            ->where('status', 'active')
            ->whereIn('role', ['dealer', 'sub_dealer'])
            ->where(function ($query) use ($normalized): void {
                $query->whereRaw('UPPER(refer_code) = ?', [$normalized])
                    ->orWhereRaw('UPPER(dealer_code) = ?', [$normalized]);
            })
            ->first();

        if (! $partner) {
            // Unknown code still opens the company contact form with the code prefilled.
            return redirect('/contact?referred_by='.rawurlencode($normalized).'&topic=partnership_enquiry#enquiry');
        }

        $social = SocialShareMeta::referral($partner);

        return view('reference.join', [
            'partner' => $partner,
            'code' => (string) ($partner->refer_code ?: $partner->dealer_code ?: $normalized),
            'social' => $social,
        ]);
    }

    private function resolveSharePartner(Request $request): ?User
    {
        $ref = strtoupper(trim((string) $request->query('ref', '')));
        if ($ref !== '') {
            $fromRef = User::query()
                ->where('status', 'active')
                ->whereIn('role', ['dealer', 'sub_dealer'])
                ->where(function ($query) use ($ref): void {
                    $query->whereRaw('UPPER(refer_code) = ?', [$ref])
                        ->orWhereRaw('UPPER(dealer_code) = ?', [$ref]);
                })
                ->first();
            if ($fromRef) {
                return $fromRef;
            }
        }

        $user = Auth::user();
        if ($user instanceof User && in_array($user->role, ['dealer', 'sub_dealer', 'super_admin', 'md', 'chairman', 'employee', 'sr', 'finance', 'account_manager'], true)) {
            if (in_array($user->role, ['dealer', 'sub_dealer'], true)) {
                return $user;
            }
        }

        return null;
    }

    public function apply(Request $request, PublicPartnerApplication $applications): RedirectResponse
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'email' => 'required|email|max:254',
            'phone' => 'required|string|max:30',
            'role' => ['required', Rule::in(['dealer', 'sub_dealer'])],
            'division' => 'required|string|max:80',
            'district' => 'required|string|max:80',
            'upazila' => 'nullable|string|max:150',
            'sponsor_code' => 'nullable|string|max:40',
            'message' => 'nullable|string|max:2000',
            'website' => 'nullable|max:0',
        ]);
        unset($data['website']);
        if (! empty($data['sponsor_code'])) {
            $data['sponsor_code'] = strtoupper(trim((string) $data['sponsor_code']));
        }
        $applications->submit($data);

        return redirect('/partnership')->with('success', __('portal.flash_partnership_received'));
    }
}
