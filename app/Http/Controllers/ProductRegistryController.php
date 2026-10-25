<?php

namespace App\Http\Controllers;

use App\Domain\Finance\Percentage;
use App\Domain\Inventory\StockNotifications;
use App\Models\Product;
use App\Support\Audit;
use App\Support\Money;
use App\Support\ProductVideo;
use App\Support\SiteCustomization;
use App\Support\YouTubeVideo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProductRegistryController extends Controller
{
    public function __construct(private StockNotifications $notifications) {}

    public function edit(Request $r, ?Product $product = null): Response
    {
        abort_unless($r->user()->staff(), 403);
        abort_unless($r->user()->allows('catalog') || $r->user()->allows('inventory') || $r->user()->allows('pricing') || $r->user()->allows('cost_read'), 403);
        if (! $product) {
            abort_unless($r->user()->allows('catalog') && $r->user()->allows('pricing'), 403);
        }
        $data = $product ? [...$product->only(['id', 'name', 'sku', 'category', 'description', 'unit', 'mrp', 'active', 'featured', 'video_source']), ...$product->media(), 'youtube_url' => $product->video_youtube_id ? 'https://www.youtube.com/watch?v='.$product->video_youtube_id : '', 'version' => (string) $product->registry_version, 'stock_status' => $product->stockStatus()] : null;
        if ($data && ($r->user()->allows('pricing') || $r->user()->allows('orders') || $r->user()->allows('finance'))) {
            $data['dealer_price'] = $product->dealer_price;
            $data['sub_dealer_price'] = $product->sub_dealer_price;
        }
        if ($data && $r->user()->allows('inventory')) {
            $data['stock'] = $product->stock;
            $data['low_stock_at'] = $product->low_stock_at;
        }
        if ($data && $r->user()->allows('cost_read')) {
            $data['cost_price'] = $product->cost_price;
        }
        if ($data) {
            $data['vimeo_url'] = $product->video_vimeo_id ? 'https://vimeo.com/'.$product->video_vimeo_id.($product->video_vimeo_hash ? '/'.$product->video_vimeo_hash : '') : '';
            $data['external_video_url'] = $product->video_external_url ?? '';
        }

        return Inertia::render('ProductEditor', ['product' => $data, 'categories' => SiteCustomization::getConfiguredCategoryNames()]);
    }

    public function store(Request $r): RedirectResponse
    {
        abort_unless($r->user()->allows('catalog') && $r->user()->allows('pricing'), 403);

        return $this->save($r);
    }

    public function update(Request $r, Product $product): RedirectResponse
    {
        abort_unless($r->user()->allows('catalog') || $r->user()->allows('inventory') || $r->user()->allows('pricing'), 403);

        return $this->save($r, $product);
    }

    public function destroy(Request $r, Product $product): RedirectResponse
    {
        abort_unless($r->user()->allows('catalog'), 403);
        $data = $r->validate(['reason' => 'required|string|min:5|max:1000']);
        $archived = DB::transaction(function () use ($r, $product, $data): bool {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $hasHistory = DB::table('order_lines')->where('product_id', $locked->id)->exists()
                || DB::table('stock_movements')->where('product_id', $locked->id)->exists() || $locked->stock > 0;
            if ($hasHistory) {
                $locked->update(['active' => false, 'featured' => false, 'registry_version' => $locked->registry_version + 1]);
                Audit::record($r->user()->id, 'product.archived', (string) $locked->id, ['reason' => $data['reason']]);

                return true;
            }
            $media = array_filter([$locked->image_path, $locked->video_path]);
            $locked->delete();
            DB::afterCommit(function () use ($media): void {
                foreach ($media as $path) {
                    Storage::disk('local')->delete($path);
                }
            });
            Audit::record($r->user()->id, 'product.deleted', (string) $product->id, ['name' => $product->name, 'reason' => $data['reason']]);

            return false;
        }, 3);

        return redirect('/catalog')->with('success', $archived ? 'Product archived with its inventory and order history preserved.' : 'Unused product deleted.');
    }

    public function toggleStock(Request $r, Product $product): RedirectResponse
    {
        abort_unless($r->user()->allows('inventory'), 403);
        $paused = DB::transaction(function () use ($r, $product): bool {
            $locked = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $paused = ! $locked->is_stock_paused;
            $locked->update(['is_stock_paused' => $paused, 'registry_version' => $locked->registry_version + 1]);
            $this->notifications->recordChange($locked, $locked->stock, $paused && $locked->stock > 0);
            Audit::record($r->user()->id, 'stock.quick_toggle', (string) $locked->id, ['ordering_paused' => $paused, 'stock_on_hand' => $locked->stock]);

            return $paused;
        }, 3);

        return back()->with('success', $paused ? 'Ordering paused for this product.' : 'Ordering resumed. Stock quantities remain controlled by Inventory.');
    }

    private function save(Request $r, ?Product $product = null): RedirectResponse
    {
        $catalog = $r->user()->allows('catalog');
        $pricing = $r->user()->allows('pricing');
        $inventory = $r->user()->allows('inventory');
        $required = $product ? 'sometimes|required' : 'required';
        $rules = ['name' => $catalog ? $required.'|string|max:150' : 'prohibited', 'sku' => $catalog ? [...explode('|', $required), 'string', 'max:60', Rule::unique('products')->ignore($product?->id)] : 'prohibited', 'category' => $catalog ? $required.'|string|max:100' : 'prohibited', 'description' => $catalog ? 'nullable|string|max:3000' : 'prohibited', 'unit' => $catalog ? $required.'|string|max:30' : 'prohibited', 'active' => $catalog ? 'sometimes|boolean' : 'prohibited', 'featured' => $catalog ? 'sometimes|boolean' : 'prohibited', 'mrp' => $pricing ? $required.'|regex:/^\d{1,8}(\.\d{1,2})?$/' : 'prohibited', 'dealer_price' => $pricing ? 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/' : 'prohibited', 'cost_price' => ($pricing && $r->user()->allows('cost_read')) ? 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/' : 'prohibited', 'low_stock_at' => $inventory ? 'sometimes|integer|min:0|max:100000' : 'prohibited', 'reason' => 'required|string|min:5|max:1000', 'version' => $product ? 'required|string|max:80' : 'nullable', 'stock' => 'prohibited', 'image_path' => 'prohibited', 'video_path' => 'prohibited', 'video_youtube_id' => 'prohibited',
            'image' => $catalog ? 'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096' : 'prohibited', 'remove_image' => $catalog ? 'sometimes|boolean' : 'prohibited', 'video_source' => $catalog ? 'sometimes|in:none,youtube,vimeo,url,upload' : 'prohibited', 'youtube_url' => $catalog ? 'nullable|string|max:500' : 'prohibited', 'vimeo_url' => $catalog ? 'nullable|string|max:500' : 'prohibited', 'external_video_url' => $catalog ? 'nullable|string|max:2000' : 'prohibited', 'video' => $catalog ? 'nullable|file|mimetypes:video/mp4,video/webm|max:102400' : 'prohibited',
            'dealer_discount_pct' => $pricing ? 'nullable|numeric|between:20,50|decimal:0,2' : 'prohibited',
            'sub_dealer_price' => $pricing ? 'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/' : 'prohibited',
            'sub_dealer_discount_pct' => $pricing ? 'nullable|numeric|between:20,50|decimal:0,2' : 'prohibited',
            'redirect_to' => 'nullable|string|max:50',
            'initial_stock' => $inventory ? 'nullable|integer|min:0|max:100000' : 'nullable|integer|in:0'];
        $d = $r->validate($rules);
        if (! $r->user()->allows('cost_read')) {
            unset($d['cost_price']);
        }
        $mrp = $d['mrp'] ?? $product?->mrp;
        $dealer = array_key_exists('dealer_price', $d) && $d['dealer_price'] !== null && $d['dealer_price'] !== '' ? $d['dealer_price'] : $product?->dealer_price;
        if (isset($d['dealer_discount_pct']) && $mrp) {
            $rate = Percentage::from((string) $d['dealer_discount_pct']);
            $dealer = Money::decimal(Percentage::from(Money::decimal(10000 - $rate->basisPoints))->of(Money::cents($mrp), 'half_up'));
            $d['dealer_price'] = $dealer;
        }
        if (isset($d['sub_dealer_discount_pct']) && $mrp) {
            $rate = Percentage::from((string) $d['sub_dealer_discount_pct']);
            $d['sub_dealer_price'] = Money::decimal(Percentage::from(Money::decimal(10000 - $rate->basisPoints))->of(Money::cents($mrp), 'half_up'));
        }
        foreach (['dealer_price' => $dealer, 'sub_dealer_price' => $d['sub_dealer_price'] ?? $product?->sub_dealer_price] as $field => $price) {
            if ($price !== null && (array_key_exists($field, $d) || array_key_exists('mrp', $d))) {
                $value = Money::cents($price);
                $retail = Money::cents($mrp);
                if ($value < intdiv($retail * 50 + 50, 100) || $value > intdiv($retail * 80 + 50, 100)) {
                    throw ValidationException::withMessages([$field => __('portal.err_prod_discount_range')]);
                }
            }
        }
        if (Money::cents($mrp) <= 0 || ($dealer !== null && (Money::cents($dealer) <= 0 || Money::cents($dealer) > Money::cents($mrp)))) {
            throw ValidationException::withMessages(['mrp' => __('portal.err_prod_price_positive')]);
        }
        $source = $d['video_source'] ?? $product?->video_source ?? 'none';
        $id = null;
        $vimeo = null;
        $external = null;
        if (array_key_exists('video_source', $d) && $source === 'vimeo') {
            $vimeo = ProductVideo::vimeo($d['vimeo_url'] ?? '');
            if (! $vimeo) {
                throw ValidationException::withMessages(['vimeo_url' => __('portal.err_prod_vimeo')]);
            }
        }
        if (array_key_exists('video_source', $d) && $source === 'url') {
            $external = ProductVideo::direct($d['external_video_url'] ?? '');
            if (! $external) {
                throw ValidationException::withMessages(['external_video_url' => __('portal.err_prod_external_video')]);
            }
        }
        if (array_key_exists('video_source', $d) && $source === 'youtube') {
            $id = YouTubeVideo::id($d['youtube_url'] ?? '');
            if (! $id) {
                throw ValidationException::withMessages(['youtube_url' => __('portal.err_prod_youtube')]);
            }
        }
        if ($source === 'upload' && ! $r->hasFile('video') && ! ($product?->video_source === 'upload' && $product?->video_path)) {
            throw ValidationException::withMessages(['video' => __('portal.err_prod_video_file')]);
        }
        if ($r->hasFile('video') && $source !== 'upload') {
            throw ValidationException::withMessages(['video' => __('portal.err_prod_video_mode')]);
        }
        if ($r->boolean('remove_image') && $r->hasFile('image')) {
            throw ValidationException::withMessages(['image' => __('portal.err_prod_image_action')]);
        }
        $stored = [];
        $old = [];
        try {
            foreach (['image', 'video'] as $field) {
                if ($r->hasFile($field)) {
                    $file = $r->file($field);
                    $extension = match ($file->getMimeType()) {
                        'image/jpeg' => 'jpg','image/png' => 'png','image/webp' => 'webp','video/mp4' => 'mp4','video/webm' => 'webm',default => throw ValidationException::withMessages([$field => __('portal.err_prod_media_format')])
                    };
                    $path = $file->storeAs('product-media/'.($field === 'image' ? 'images' : 'videos'), Str::uuid().'.'.$extension, 'local');
                    if (! $path) {
                        throw new \RuntimeException('Media storage is unavailable.');
                    }$stored[$field] = $path;
                }
            }
            $initialStock = (! $product && $r->filled('initial_stock') && (int) $r->input('initial_stock') > 0) ? (int) $r->input('initial_stock') : 0;
            $saved = DB::transaction(function () use ($r, $product, $d, $source, $id, $vimeo, $external, $stored, &$old, $initialStock) {
                $p = $product ? Product::whereKey($product->id)->lockForUpdate()->firstOrFail() : new Product;
                $previousStock = (int) ($p->stock ?? 0);
                $wasAvailable = $p->exists && $p->active && $previousStock > 0 && ! $p->is_stock_paused;
                if ($product && (string) $p->registry_version !== (string) $d['version']) {
                    throw ValidationException::withMessages(['version' => __('portal.err_prod_stale')]);
                }
                $attributes = collect($d)->only(['name', 'sku', 'category', 'description', 'unit', 'active', 'featured', 'mrp', 'dealer_price', 'sub_dealer_price', 'cost_price', 'low_stock_at'])->all();
                if (! $product && $initialStock > 0) {
                    $attributes['stock'] = $initialStock;
                }
                if (isset($stored['image']) || $r->boolean('remove_image')) {
                    $old[] = $p->image_path;
                    $attributes['image_path'] = $stored['image'] ?? null;
                }
                if (array_key_exists('video_source', $d)) {
                    $attributes['video_source'] = $source === 'none' ? null : $source;
                    $attributes['video_youtube_id'] = $source === 'youtube' ? $id : null;
                    $attributes['video_vimeo_id'] = $source === 'vimeo' ? $vimeo['id'] : null;
                    $attributes['video_vimeo_hash'] = $source === 'vimeo' ? $vimeo['hash'] : null;
                    $attributes['video_external_url'] = $source === 'url' ? $external : null;
                    if ($source !== 'upload' || isset($stored['video'])) {
                        $old[] = $p->video_path;
                        $attributes['video_path'] = $source === 'upload' ? ($stored['video'] ?? null) : null;
                        $attributes['video_mime'] = $source === 'upload' ? $r->file('video')?->getMimeType() : null;
                    }
                }
                $attributes['registry_version'] = $p->registry_version + 1;
                $p->fill($attributes);
                $p->updated_at = now();
                $p->save();
                if (! $product && $initialStock > 0) {
                    DB::table('stock_movements')->insert([
                        'product_id' => $p->id, 'quantity' => $initialStock, 'kind' => 'receipt',
                        'reason' => 'Initial stock on product onboarding: '.$d['reason'],
                        'reference' => (string) Str::uuid(), 'actor_id' => $r->user()->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                Audit::record($r->user()->id, $product ? 'product.updated' : 'product.created', (string) $p->id, ['fields' => array_keys($attributes), 'reason' => $d['reason']]);
                $this->notifications->recordChange($p->refresh(), $previousStock, $wasAvailable);

                return $p;
            });
        } catch (\Throwable $e) {
            foreach ($stored as $path) {
                Storage::disk('local')->delete($path);
            }throw $e;
        }
        foreach (array_filter($old) as $path) {
            Storage::disk('local')->delete($path);
        }
        if ($r->input('redirect_to') === 'catalog') {
            $stockNotice = $initialStock > 0 ? " ({$initialStock} units in stock)" : '';

            return redirect('/catalog')->with('success', $product ? "Product '{$saved->name}' updated." : "Product '{$saved->name}' created successfully{$stockNotice}.");
        }

        return redirect('/products/'.$saved->id)->with('success', $product ? 'Product updated.' : 'Product created. Receive stock through Inventory before dispatch.');
    }

    public function image(Request $r, Product $product): BinaryFileResponse
    {
        $this->mediaAccess($r, $product);
        abort_unless($product->image_path && Storage::disk('local')->exists($product->image_path), 404);

        return response()->file(Storage::disk('local')->path($product->image_path), ['Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function video(Request $r, Product $product): BinaryFileResponse
    {
        $this->mediaAccess($r, $product);
        abort_unless($product->video_source === 'upload' && $product->video_path && Storage::disk('local')->exists($product->video_path), 404);

        return response()->file(Storage::disk('local')->path($product->video_path), ['Content-Type' => $product->video_mime, 'Cache-Control' => 'no-cache', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function mediaAccess(Request $r, Product $p): void
    {
        $user = $r->user();
        $canReadArchived = $user?->staff() && collect(['catalog', 'inventory', 'pricing', 'cost_read', 'orders', 'finance'])
            ->contains(fn (string $capability): bool => $user->allows($capability));
        abort_unless($p->active || $canReadArchived, 404);
    }
}
