<?php

namespace App\Http\Controllers;

use App\Domain\Audience\PublicPartnerApplication;
use App\Domain\Integrations\MapsPresentation;
use App\Domain\Notifications\PartnerEventSms;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
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
        $products = Product::where('active', true)
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->orderBy('name')->paginate($page === 'home' ? 4 : 12, ['id', 'name', 'sku', 'category', 'description', 'mrp', 'unit', 'tone'])->withQueryString();
        $directory = User::where('directory_visible', true)->where('status', 'active')->where('role', 'dealer');
        $dealerCols = ['name', 'dealer_code', 'phone', 'division', 'district', 'latitude', 'longitude', 'profile'];
        if ($page === 'dealers' && $request->filled(['lat', 'lng'])) {
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
        $featuredProducts = SiteCustomization::getFeaturedProducts();
        $featuredCategoryTabs = SiteCustomization::getFeaturedCategoryTabs($featuredProducts);
        $featuredItems = $featuredProducts;

        $siteCustomization = SiteCustomization::all();
        $officeAddress = (string) ($siteCustomization['office_address'] ?? 'Thana Road, Pirganj 5470, Rangpur, Bangladesh');
        $maps = app(MapsPresentation::class)->forAddress($officeAddress);

        return view($page === 'home' ? 'reference.home' : 'reference.information', compact('page', 'products', 'dealers', 'districts', 'divisions', 'featuredItems', 'featuredProducts', 'featuredCategoryTabs') + [
            'content' => DB::table('content_pages')->where('slug', $page)->where('published', true)->first(),
            'notice' => DB::table('content_pages')->where('slug', 'notice')->where('published', true)->first(),
            'categories' => Product::where('active', true)->orderBy('category')->distinct()->pluck('category'),
            'maps' => $maps,
        ]);
    }

    public function product(Product $product): View
    {
        abort_unless(SiteCustomization::isPageEnabled('products'), 404);
        abort_unless($product->active, 404);

        return view('reference.product', ['item' => $this->publicProduct($product)]);
    }

    private function publicProduct(Product $p): array
    {
        return [
            ...$p->only(['id', 'name', 'sku', 'category', 'description', 'unit']),
            'price' => $p->mrp,
            'mrp' => $p->mrp,
            'stock_status' => $p->stockStatus(),
            'url' => route('products.show', $p->id),
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
        $catalogCategories = Product::where('active', true)->orderBy('category')->distinct()->pluck('category');

        return view('reference.catalog', compact('catalog', 'catalogCategories'));
    }

    public function enquiry(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'phone' => 'required|string|max:30',
            'topic' => 'required|in:dealer_enquiry,partnership_enquiry,product_enquiry,support,complaint,Dealer enquiry,Partnership enquiry,Product enquiry,Support,Complaint',
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
        DB::table('enquiries')->insert([
            ...$data,
            'status' => 'new',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $locale = app()->getLocale();
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

    public function join(string $code): RedirectResponse
    {
        abort_unless(SiteCustomization::isPageEnabled('partnership'), 404);
        $normalized = strtoupper(trim($code));
        abort_if($normalized === '' || strlen($normalized) > 40, 404);

        return redirect('/partnership?sponsor_code='.rawurlencode($normalized).'#partner-apply');
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
