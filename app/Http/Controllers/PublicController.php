<?php

namespace App\Http\Controllers;

use App\Models\{Product, User};
use Illuminate\Contracts\View\View;
use Illuminate\Http\{RedirectResponse, Request};
use Illuminate\Support\Facades\DB;
use App\Support\{ReferenceCatalog,Money,SiteCustomization};
use Illuminate\Pagination\LengthAwarePaginator;

class PublicController extends Controller
{
    public function index(Request $request, string $page = 'home'): View
    {
        abort_unless(in_array($page, ['home', 'products', 'dealers', 'about', 'contact'], true), 404);
        $request->validate(['q' => 'nullable|string|max:100', 'district' => 'nullable|string|max:80',
            'division' => 'nullable|string|max:80', 'category' => 'nullable|string|max:100',
            'page'=>'nullable|integer|min:1','sort'=>'nullable|in:name,price_asc,price_desc','in_stock'=>'nullable|in:0,1',
            'min_price'=>'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/','max_price'=>'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/',
            'lat'=>'nullable|numeric|between:-90,90','lng'=>'nullable|numeric|between:-180,180']);
        if($page==='products')return $this->catalog($request);
        $products = Product::where('active', true)
            ->when($request->filled('q'), fn ($query) => $query->where('name', 'like', '%'.$request->string('q').'%'))
            ->when($request->filled('category'), fn ($query) => $query->where('category', $request->string('category')))
            ->orderBy('name')->paginate($page === 'home' ? 4 : 12, ['id', 'name', 'sku', 'category', 'description', 'mrp', 'unit', 'stock', 'tone'])->withQueryString();
        $directory = User::where('directory_visible', true)->where('status', 'active')->where('role', 'dealer');
        $dealerCols = ['name', 'dealer_code', 'phone', 'division', 'district', 'latitude', 'longitude', 'profile'];
        if($page === 'dealers' && $request->filled(['lat', 'lng'])) {
            $userLat = (float) $request->input('lat');
            $userLng = (float) $request->input('lng');
            $allDealers = (clone $directory)
                ->when($request->filled('division'), fn ($query) => $query->where('division', $request->string('division')))
                ->when($request->filled('district'), fn ($query) => $query->where('district', $request->string('district')))
                ->get($dealerCols)
                ->map(function ($dealer) use ($userLat, $userLng) {
                    if ($dealer->latitude !== null && $dealer->longitude !== null) {
                        $lat1 = deg2rad($userLat); $lon1 = deg2rad($userLng);
                        $lat2 = deg2rad((float)$dealer->latitude); $lon2 = deg2rad((float)$dealer->longitude);
                        $dLat = $lat2 - $lat1; $dLon = $lon2 - $lon1;
                        $a = sin($dLat/2)**2 + cos($lat1) * cos($lat2) * sin($dLon/2)**2;
                        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
                        $dealer->distance_km = round(6371 * $c, 1);
                    } else {
                        $dealer->distance_km = null;
                    }
                    return $dealer;
                })->sort(function ($a, $b) {
                    if ($a->distance_km === null && $b->distance_km === null) return strcmp($a->name, $b->name);
                    if ($a->distance_km === null) return 1;
                    if ($b->distance_km === null) return -1;
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
        $referencePreview=ReferenceCatalog::enabled()&&!Product::where('active',true)->where('sku','not like','DEMO-%')->exists();
        $featuredProducts = SiteCustomization::getFeaturedProducts();
        $featuredCategoryTabs = SiteCustomization::getFeaturedCategoryTabs($featuredProducts);
        $featuredItems = $featuredProducts;
        return view($page === 'home' ? 'reference.home' : 'reference.information', compact('page', 'products', 'dealers', 'districts', 'divisions','referencePreview','featuredItems','featuredProducts','featuredCategoryTabs') + [
            'content' => DB::table('content_pages')->where('slug', $page)->where('published', true)->first(),
            'notice' => DB::table('content_pages')->where('slug', 'notice')->where('published', true)->first(),
            'categories' => Product::where('active', true)->orderBy('category')->distinct()->pluck('category'),
        ]);
    }

    public function product(Product $product): View
    {
        abort_unless($product->active, 404);
        return view('reference.product',['item'=>$this->publicProduct($product)]);
    }

    public function previewProduct(string $id):View {
        abort_unless(ReferenceCatalog::enabled(),404);$item=ReferenceCatalog::find($id);abort_unless($item,404);
        return view('reference.product',compact('item'));
    }

    private function publicProduct(Product $p):array {
        $campaign = SiteCustomization::getCampaignForProduct($p->id, $p->name);
        $price = $campaign && !empty($campaign['price']) ? $campaign['price'] : $p->mrp;
        $mrp = $campaign && !empty($campaign['mrp']) ? $campaign['mrp'] : $p->mrp;

        return [
            ...$p->only(['id','name','sku','category','description','unit','stock']),
            'price' => $price,
            'mrp' => $mrp,
            'campaign' => $campaign,
            'url' => route('products.show', $p->id),
            'image_path' => $p->media()['image_url'],
            ...$p->media(),
            'preview' => false
        ];
    }

    private function catalog(Request $r):View {
        $preview=ReferenceCatalog::enabled()&&!Product::where('active',true)->where('sku','not like','DEMO-%')->exists();
        if(!$preview){
            $query=Product::where('active',true)->when($r->filled('q'),fn($q)=>$q->where('name','ilike','%'.$r->string('q').'%'))->when($r->filled('category'),fn($q)=>$q->where('category','ilike',trim((string)$r->string('category'))))->when($r->boolean('in_stock'),fn($q)=>$q->where('stock','>',0))->when($r->filled('min_price'),fn($q)=>$q->where('mrp','>=',(string)$r->string('min_price')))->when($r->filled('max_price'),fn($q)=>$q->where('mrp','<=',(string)$r->string('max_price')));
            $sort=$r->string('sort','name')->toString();$sort==='price_desc'?$query->orderByDesc('mrp'):($sort==='price_asc'?$query->orderBy('mrp'):$query->orderBy('name'));$query->orderBy('id');
            $catalog=$query->paginate(12)->withQueryString()->through(fn(Product $p)=>$this->publicProduct($p));$catalogCategories=Product::where('active',true)->orderBy('category')->distinct()->pluck('category');
            return view('reference.catalog',compact('catalog','catalogCategories','preview'));
        }
        $items=$preview?collect(ReferenceCatalog::items()):Product::where('active',true)->get()->map(fn(Product $p)=>$this->publicProduct($p));
        $catalogCategories=$items->pluck('category')->unique()->sort()->values();
        if($r->filled('q'))$items=$items->filter(fn($p)=>str_contains(mb_strtolower($p['name']),mb_strtolower((string)$r->string('q'))));
        if($r->filled('category'))$items=$items->filter(fn($p)=>mb_strtolower(trim($p['category']))===mb_strtolower(trim((string)$r->string('category'))));
        if($r->boolean('in_stock'))$items=$items->filter(fn($p)=>$p['stock']>0);
        if($r->filled('min_price'))$items=$items->filter(fn($p)=>Money::cents($p['price'])>=Money::cents((string)$r->string('min_price')));
        if($r->filled('max_price'))$items=$items->filter(fn($p)=>Money::cents($p['price'])<=Money::cents((string)$r->string('max_price')));
        $sort=$r->string('sort','name')->toString();
        $items=$sort==='price_desc'?$items->sortByDesc(fn($p)=>Money::cents($p['price'])):($sort==='price_asc'?$items->sortBy(fn($p)=>Money::cents($p['price'])):$items->sortBy('name'));
        $page=$r->integer('page',1);$catalog=new LengthAwarePaginator($items->values()->slice(($page-1)*12,12),$items->count(),12,$page,['path'=>'/products','query'=>$r->query()]);
        return view('reference.catalog',compact('catalog','catalogCategories','preview'));
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
