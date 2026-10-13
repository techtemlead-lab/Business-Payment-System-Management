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
        return Inertia::render('ProductEditor',['product'=>$data,'categories'=>Product::orderBy('category')->distinct()->pluck('category')]);
    }
    public function store(Request $r){abort_unless($r->user()->allows('catalog')&&$r->user()->allows('pricing'),403);return $this->save($r);}
    public function update(Request $r,Product $product){abort_unless($r->user()->allows('catalog')||$r->user()->allows('inventory'),403);return $this->save($r,$product);}
    private function save(Request $r,?Product $product=null){
        $catalog=$r->user()->allows('catalog');$pricing=$r->user()->allows('pricing');$inventory=$r->user()->allows('inventory');$required=$product?'sometimes|required':'required';
        $rules=['name'=>$catalog?$required.'|string|max:150':'prohibited','sku'=>$catalog?[...explode('|',$required),'string','max:60',Rule::unique('products')->ignore($product?->id)]:'prohibited','category'=>$catalog?$required.'|string|max:100':'prohibited','description'=>$catalog?'nullable|string|max:3000':'prohibited','unit'=>$catalog?$required.'|string|max:30':'prohibited','active'=>$catalog?'sometimes|boolean':'prohibited','featured'=>$catalog?'sometimes|boolean':'prohibited','mrp'=>$pricing?$required.'|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','dealer_price'=>$pricing?'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','cost_price'=>$pricing?'nullable|regex:/^\d{1,8}(\.\d{1,2})?$/':'prohibited','low_stock_at'=>$inventory?'sometimes|integer|min:0|max:100000':'prohibited','reason'=>'required|string|min:5|max:1000','version'=>$product?'required|string|max:80':'nullable','stock'=>'prohibited','image_path'=>'prohibited','video_path'=>'prohibited','video_youtube_id'=>'prohibited',
            'image'=>$catalog?'nullable|file|image|mimes:jpg,jpeg,png,webp|max:5120|dimensions:max_width=4096,max_height=4096':'prohibited','remove_image'=>$catalog?'sometimes|boolean':'prohibited','video_source'=>$catalog?'sometimes|in:none,youtube,upload':'prohibited','youtube_url'=>$catalog?'nullable|string|max:500':'prohibited','video'=>$catalog?'nullable|file|mimetypes:video/mp4,video/webm|max:102400':'prohibited'];
        $d=$r->validate($rules);$mrp=$d['mrp']??$product?->mrp;$dealer=array_key_exists('dealer_price',$d)?$d['dealer_price']:$product?->dealer_price;
        if(Money::cents($mrp)<=0||($dealer!==null&&(Money::cents($dealer)<=0||Money::cents($dealer)>Money::cents($mrp))))throw ValidationException::withMessages(['mrp'=>'Enter positive prices; dealer price cannot exceed MRP.']);
        $source=$d['video_source']??$product?->video_source??'none';$id=null;
        if(array_key_exists('video_source',$d)&&$source==='youtube'){$id=YouTubeVideo::id($d['youtube_url']??'');if(!$id)throw ValidationException::withMessages(['youtube_url'=>'Enter a valid YouTube watch, share or Shorts URL.']);}
        if($source==='upload'&&!$r->hasFile('video')&&!($product?->video_source==='upload'&&$product?->video_path))throw ValidationException::withMessages(['video'=>'Choose an MP4 or WebM video to upload.']);
        if($r->hasFile('video')&&$source!=='upload')throw ValidationException::withMessages(['video'=>'Select Upload Video before choosing a file.']);
        if($r->boolean('remove_image')&&$r->hasFile('image'))throw ValidationException::withMessages(['image'=>'Choose an image replacement or removal.']);
        $stored=[];$old=[];
        try{
            foreach(['image','video'] as $field)if($r->hasFile($field)){$file=$r->file($field);$extension=match($file->getMimeType()){'image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','video/mp4'=>'mp4','video/webm'=>'webm',default=>throw ValidationException::withMessages([$field=>'Unsupported media format.'])};$path=$file->storeAs('product-media/'.($field==='image'?'images':'videos'),Str::uuid().'.'.$extension,'local');if(!$path)throw new \RuntimeException('Media storage is unavailable.');$stored[$field]=$path;}
            $saved=DB::transaction(function()use($r,$product,$d,$source,$id,$stored,&$old){
                $p=$product?Product::whereKey($product->id)->lockForUpdate()->firstOrFail():new Product;
                if($product&&(string)$p->registry_version!==(string)$d['version'])throw ValidationException::withMessages(['version'=>'This product changed. Reload its details before saving.']);
                $attributes=collect($d)->only(['name','sku','category','description','unit','active','featured','mrp','dealer_price','cost_price','low_stock_at'])->all();
                if(isset($stored['image'])||$r->boolean('remove_image')){$old[]=$p->image_path;$attributes['image_path']=$stored['image']??null;}
                if(array_key_exists('video_source',$d)){$attributes['video_source']=$source==='none'?null:$source;$attributes['video_youtube_id']=$source==='youtube'?$id:null;if($source!=='upload'||isset($stored['video'])){$old[]=$p->video_path;$attributes['video_path']=$source==='upload'?($stored['video']??null):null;$attributes['video_mime']=$source==='upload'?$r->file('video')?->getMimeType():null;}}
                $attributes['registry_version']=$p->registry_version+1;$p->fill($attributes);$p->updated_at=now();$p->save();
                Audit::record($r->user()->id,$product?'product.updated':'product.created',(string)$p->id,['fields'=>array_keys($attributes),'reason'=>$d['reason']]);return $p;
            });
        }catch(\Throwable $e){foreach($stored as $path)Storage::disk('local')->delete($path);throw $e;}
        foreach(array_filter($old) as $path)Storage::disk('local')->delete($path);
        return redirect('/company/products/'.$saved->id)->with('success',$product?'Product updated.':'Product created. Receive stock through Inventory before dispatch.');
    }
    public function image(Request $r,Product $product){$this->mediaAccess($r,$product);abort_unless($product->image_path&&Storage::disk('local')->exists($product->image_path),404);return response()->file(Storage::disk('local')->path($product->image_path),['Cache-Control'=>'no-cache','X-Content-Type-Options'=>'nosniff']);}
    public function video(Request $r,Product $product){$this->mediaAccess($r,$product);abort_unless($product->video_source==='upload'&&$product->video_path&&Storage::disk('local')->exists($product->video_path),404);return response()->file(Storage::disk('local')->path($product->video_path),['Content-Type'=>$product->video_mime,'Cache-Control'=>'no-cache','X-Content-Type-Options'=>'nosniff']);}
    private function mediaAccess(Request $r,Product $p):void{abort_unless($p->active||($r->user()?->status==='active'&&$r->user()?->staff()),404);}
}

