<?php

namespace App\Http\Controllers;

use App\Domain\Integrations\MapsPresentation;
use App\Models\Product;
use App\Models\User;
use App\Support\SiteCustomization;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class PublicController extends Controller
{
    public function index(Request $request, string $page = 'home'): View
    {
        abort_unless(in_array($page, ['home', 'products', 'dealers', 'about', 'contact', 'partnership', 'services', 'quality', 'faq', 'privacy', 'terms'], true), 404);
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
            ->orderBy('name')->paginate($page === 'home' ? 4 : 12, ['id', 'name', 'sku', 'category', 'description', 'mrp', 'unit', 'stock', 'tone'])->withQueryString();
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
            ...$p->only(['id', 'name', 'sku', 'category', 'description', 'unit', 'stock']),
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
        $data = $request->validate(['name' => 'required|string|max:150', 'phone' => 'required|string|max:30',
            'topic' => 'required|in:Dealer enquiry,Product enquiry,Support,Complaint', 'message' => 'required|string|min:10|max:2000', 'website' => 'nullable|max:0']);
        unset($data['website']);
        DB::table('enquiries')->insert([...$data, 'created_at' => now(), 'updated_at' => now()]);

        return back()->with('success', 'Your enquiry has been received. The company will contact you.');
    }
}
