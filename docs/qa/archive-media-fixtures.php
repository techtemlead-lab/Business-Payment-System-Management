<?php

use App\Models\Product;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! app()->environment('local')) {
    throw new RuntimeException('Synthetic fixture cleanup is local-only.');
}
$actor = User::where('email', 'admin@madina.test')->where('role', 'super_admin')->firstOrFail();
$count = 0;
foreach (Product::where('name', 'Local QA Media Product')->where('sku', 'like', 'QA-MEDIA-%')->where('active', true)->get() as $product) {
    DB::transaction(function () use ($product, $actor) {
        $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();
        $p->update(['active' => false, 'registry_version' => $p->registry_version + 1]);
        Audit::record($actor->id, 'product.qa_archived', (string) $p->id, ['reason' => 'Archive the synthetic local browser QA product']);
    });
    $count++;
}echo json_encode(['synthetic_products_archived' => $count]);
