<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local'))exit(1);
$c=json_decode(file_get_contents(base_path('.local/demo-access.json')),true);
$result=[];
foreach($c['accounts'] as $type=>$email){$u=\App\Models\User::where('email',$email)->first();$result[$type]=['exists'=>(bool)$u,'active'=>$u?->status==='active','credentials_match'=>$u&&\Illuminate\Support\Facades\Hash::check($c['password'],$u->password)];}
echo json_encode($result,JSON_PRETTY_PRINT);
