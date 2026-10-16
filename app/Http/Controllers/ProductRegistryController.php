<?php
namespace App\Http\Controllers;
use App\Models\Product;
use App\Support\{Audit,Money,YouTubeVideo};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB,Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\{Rule,ValidationException};
use Inertia\Inertia;

class ProductRegistryController extends Controller {
    public function edit(Request $r,?Product $product=null){
        abort_unless($r->user()->staff(),403);
        if(!$product)abort_unless($r->user()->allows('catalog')&&$r->user()->allows('pricing'),403);
        $data=$product?[...$product->only(['id','name','sku','category','description','unit','mrp','dealer_price','stock','low_stock_at','active','featured','video_source']),...$product->media(),'youtube_url'=>$product->video_youtube_id?'https://www.youtube.com/watch?v='.$product->video_youtube_id:'','version'=>(string)$product->registry_version]:null;
        if($data&&$r->user()->allows('cost_read'))$data['cost_price']=$product->cost_price;
        return Inertia::render('ProductEditor',['product'=>$data,'categories'=>\App\Support\SiteCustomization::getConfiguredCategoryNames()]);
    }
    public function store(Request $r){abort_unless($r->user()->allows('catalog')&&$r->user()->allows('pricing'),403);return $this->save($r);}
    public function update(Request $r,Product $product){abort_unless($r->user()->allows('catalog')||$r->user()->allows('inventory'),403);return $this->save($r,$product);}
    public function destroy(Request $r, Product $product) {
        abort_unless($r->user()->staff() && $r->user()->allows('catalog'), 403);
        $reason = $r->input('reason', 'Product deleted from catalog by staff');
        $hasOrders = DB::table('order_lines')->where('product_id', $product->id)->exists();

        if ($hasOrders) {
            $product->update(['active' => false, 'featured' => false]);
            Audit::record($r->user()->id, 'product.archived', (string)$product->id, [
                'name' => $product->name,
                'reason' => $reason,
                'note' => 'Preserved order historical records; deactivated from public catalog'
            ]);
            return redirect('/company/catalog')->with('success', "Product '{$product->name}' has existing order records and was deactivated/archived from the public catalog.");
        }

        if ($product->image_path) Storage::disk('local')->delete($product->image_path);
        if ($product->video_path) Storage::disk('local')->delete($product->video_path);

        $name = $product->name;
        $id = $product->id;
        $product->delete();

        Audit::record($r->user()->id, 'product.deleted', (string)$id, [
            'name' => $name,
            'reason' => $reason,
        ]);

        return redirect('/company/catalog')->with('success', "Product '{$name}' was permanently deleted.");
    }
    public function toggleStock(Request $r, Product $product)
    {
        abort_unless($r->user()->staff() && ($r->user()->allows('inventory') || $r->user()->allows('catalog')), 403);

        $newStock = DB::transaction(function () use ($r, $product) {
            $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
            $currentStock = (int)$p->stock;

            if ($currentStock > 0) {
                $p->update(['stock' => 0]);
                DB::table('stock_movements')->insert([
                    'product_id' => $p->id,
                    'quantity' => -$currentStock,
                    'kind' => 'adjustment',
                    'reference' => 'toggle-out-' . Str::uuid(),
                    'reason' => 'One-click quick toggle: marked Out of Stock from dashboard',
                    'actor_id' => $r->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record($r->user()->id, 'stock.quick_toggle', (string)$p->id, [
                    'previous_stock' => $currentStock,
                    'new_stock' => 0,
                    'status' => 'out_of_stock',
                ]);
                return 0;
            } else {
                $restoreQty = 50;
                $p->update(['stock' => $restoreQty]);
                DB::table('stock_movements')->insert([
                    'product_id' => $p->id,
                    'quantity' => $restoreQty,
                    'kind' => 'receipt',
                    'reference' => 'toggle-in-' . Str::uuid(),
                    'reason' => 'One-click quick toggle: marked In Stock from dashboard',
                    'actor_id' => $r->user()->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                Audit::record($r->user()->id, 'stock.quick_toggle', (string)$p->id, [
                    'previous_stock' => 0,
                    'new_stock' => $restoreQty,
                    'status' => 'in_stock',
                ]);
                return $restoreQty;
            }
        });

        $msg = $newStock > 0
            ? "Product '{$product->name}' is now marked as In Stock ({$newStock} units)."
            : "Product '{$product->name}' is now marked as Out of Stock.";

        return back()->with('success', $msg);
    }
    private function save(Request $r,?Product $product=null){
        $catalog=$r->user()->allows('catalog');$pricing=$r->user()->allows('pricing');$inventory=$r->user()->allows('inventory');$required=$product?'sometimes|required':'required';
        $rules=['name'=>$catalog?$required.'|string|max:150':'prohibited','sku'=>$catalog?[...explode('|',$required),'string','max:60',Rule::unique('products')->ignore($product?->id)]:'prohibited','category'=>$catalog?$required.'|string|max:100':'prohibited','description'=>$catalog?'nullable|string|max:3000':'prohibited','unit'=>$catalog?$required.'|string|max:30':'prohibited','active'=>$catalog?'sometimes|boolean':'prohibited','featured'=>$catalog?'sometimes|boolean':'prohibited','mrp'=>$pricing?$required.'|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','dealer_price'=>$pricing?'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','cost_price'=>$pricing?'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','low_stock_at'=>$inventory?'sometimes|integer|min:0|max:100000':'prohibited','reason'=>'required|string|min:5|max:1000','version'=>$product?'required|string|max:80':'nullable','stock'=>'prohibited','image_path'=>'prohibited','video_path'=>'prohibited','video_youtube_id'=>'prohibited',
            'image'=>$catalog?'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096':'prohibited','remove_image'=>$catalog?'sometimes|boolean':'prohibited','video_source'=>$catalog?'sometimes|in:none,youtube,upload':'prohibited','youtube_url'=>$catalog?'nullable|string|max:500':'prohibited','video'=>$catalog?'nullable|file|mimetypes:video/mp4,video/webm|max:102400':'prohibited',
            'dealer_discount_pct'=>'nullable|numeric|min:0|max:100','redirect_to'=>'nullable|string|max:50',
            'initial_stock'=>'nullable|integer|min:0|max:100000'];
        $d=$r->validate($rules);$mrp=$d['mrp']??$product?->mrp;
        $dealer=array_key_exists('dealer_price',$d)&&$d['dealer_price']!==null&&$d['dealer_price']!==''?$d['dealer_price']:$product?->dealer_price;
        if($dealer===null&&!empty($d['dealer_discount_pct'])&&$mrp){
            $pct=(float)$d['dealer_discount_pct'];
            $calculated=round((float)$mrp*(1-($pct/100)),2);
            $dealer=number_format($calculated,2,'.','');
            $d['dealer_price']=$dealer;
        }
        if(Money::cents($mrp)<=0||($dealer!==null&&(Money::cents($dealer)<=0||Money::cents($dealer)>Money::cents($mrp))))throw ValidationException::withMessages(['mrp'=>'Enter positive prices; dealer price cannot exceed MRP.']);
        $source=$d['video_source']??$product?->video_source??'none';$id=null;
        if(array_key_exists('video_source',$d)&&$source==='youtube'){$id=YouTubeVideo::id($d['youtube_url']??'');if(!$id)throw ValidationException::withMessages(['youtube_url'=>'Enter a valid YouTube watch, share or Shorts URL.']);}
        if($source==='upload'&&!$r->hasFile('video')&&!($product?->video_source==='upload'&&$product?->video_path))throw ValidationException::withMessages(['video'=>'Choose an MP4 or WebM video to upload.']);
        if($r->hasFile('video')&&$source!=='upload')throw ValidationException::withMessages(['video'=>'Select Upload Video before choosing a file.']);
        if($r->boolean('remove_image')&&$r->hasFile('image'))throw ValidationException::withMessages(['image'=>'Choose an image replacement or removal.']);
        $stored=[];$old=[];
        try{
            foreach(['image','video'] as $field)if($r->hasFile($field)){$file=$r->file($field);$extension=match($file->getMimeType()){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm',default=>throw ValidationException::withMessages([$field=>'Unsupported media format.'])};$path=$file->storeAs('product-media/'.($field==='image'?'images':'videos'),Str::uuid().'.'.$extension,'local');if(!$path)throw new \RuntimeException('Media storage is unavailable.');$stored[$field]=$path;}
            $initialStock = (!$product && $r->filled('initial_stock') && (int)$r->input('initial_stock') > 0) ? (int)$r->input('initial_stock') : 0;
            $saved=DB::transaction(function()use($r,$product,$d,$source,$id,$stored,&$old,$initialStock){
                $p=$product?Product::whereKey($product->id)->lockForUpdate()->firstOrFail():new Product;
                if($product&&(string)$p->registry_version!==(string)$d['version'])throw ValidationException::withMessages(['version'=>'This product changed. Reload its details before saving.']);
                $attributes=collect($d)->only(['name','sku','category','description','unit','active','featured','mrp','dealer_price','cost_price','low_stock_at'])->all();
                if(!$product && $initialStock > 0){ $attributes['stock'] = $initialStock; }
                if(isset($stored['image'])||$r->boolean('remove_image')){$old[]=$p->image_path;$attributes['image_path']=$stored['image']??null;}
                if(array_key_exists('video_source',$d)){$attributes['video_source']=$source==='none'?null:$source;$attributes['video_youtube_id']=$source==='youtube'?$id:null;if($source!=='upload'||isset($stored['video'])){$old[]=$p->video_path;$attributes['video_path']=$source==='upload'?($stored['video']??null):null;$attributes['video_mime']=$source==='upload'?$r->file('video')?->getMimeType():null;}}
                $attributes['registry_version']=$p->registry_version+1;$p->fill($attributes);$p->updated_at=now();$p->save();
                if(!$product && $initialStock > 0){
                    DB::table('stock_movements')->insert([
                        'product_id'=>$p->id,'quantity'=>$initialStock,'kind'=>'receipt',
                        'reason'=>'Initial stock on product onboarding: '.$d['reason'],
                        'reference'=>(string)Str::uuid(),'actor_id'=>$r->user()->id,
                        'created_at'=>now(),'updated_at'=>now()
                    ]);
                }
                Audit::record($r->user()->id,$product?'product.updated':'product.created',(string)$p->id,['fields'=>array_keys($attributes),'reason'=>$d['reason']]);return $p;
            });
        }catch(\Throwable $e){foreach($stored as $path)Storage::disk('local')->delete($path);throw $e;}
        foreach(array_filter($old) as $path)Storage::disk('local')->delete($path);
        if($r->input('redirect_to')==='catalog'){
            $stockNotice = $initialStock > 0 ? " ({$initialStock} units in stock)" : "";
            return redirect('/company/catalog')->with('success',$product?"Product '{$saved->name}' updated.":"Product '{$saved->name}' created successfully{$stockNotice}.");
        }
        return redirect('/company/products/'.$saved->id)->with('success',$product?'Product updated.':'Product created. Receive stock through Inventory before dispatch.');
    }
    public function image(Request $r,Product $product){$this->mediaAccess($r,$product);abort_unless($product->image_path&&Storage::disk('local')->exists($product->image_path),404);return response()->file(Storage::disk('local')->path($product->image_path),['Cache-Control'=>'no-cache','X-Content-Type-Options'=>'nosniff']);}
    public function video(Request $r,Product $product){$this->mediaAccess($r,$product);abort_unless($product->video_source==='upload'&&$product->video_path&&Storage::disk('local')->exists($product->video_path),404);return response()->file(Storage::disk('local')->path($product->video_path),['Content-Type'=>$product->video_mime,'Cache-Control'=>'no-cache','X-Content-Type-Options'=>'nosniff']);}
    private function mediaAccess(Request $r,Product $p):void{abort_unless($p->active||($r->user()?->status==='active'&&$r->user()?->staff()),404);}
}

