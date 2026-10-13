<?php
require __DIR__.'/../../vendor/autoload.php';$app=require __DIR__.'/../../bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))throw new RuntimeException('Synthetic fixture cleanup is local-only.');
$actor=\App\Models\User::where('email','admin@madina.test')->where('role','super_admin')->firstOrFail();$count=0;
foreach(\App\Models\Product::where('name','Local QA Media Product')->where('sku','like','QA-MEDIA-%')->where('active',true)->get() as $product){\Illuminate\Support\Facades\DB::transaction(function()use($product,$actor){$p=\App\Models\Product::whereKey($product->id)->lockForUpdate()->firstOrFail();$p->update(['active'=>false,'registry_version'=>$p->registry_version+1]);\App\Support\Audit::record($actor->id,'product.qa_archived',(string)$p->id,['reason'=>'Archive the synthetic local browser QA product']);});$count++;}echo json_encode(['synthetic_products_archived'=>$count]);
