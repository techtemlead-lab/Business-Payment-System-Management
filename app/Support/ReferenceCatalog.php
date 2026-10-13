<?php
namespace App\Support;

final class ReferenceCatalog
{
    public static function enabled():bool { return app()->environment('local'); }
    public static function items():array {
        $fixture=json_decode(file_get_contents(public_path('reference/home-products.json')),true,512,JSON_THROW_ON_ERROR);
        return array_map(function(array $p,int $index):array {
            $category=match(true){$index===1=>'Medical Equipment',$index===2=>'Agriculture Product',$index===3=>'Service',in_array($index,[4,5,6],true)=>'Personal Care',$index>=9=>'Unani Medicine',default=>'Food Supplement'};
            return [...$p,'category'=>$category,'url'=>'/products/preview/'.$p['id'],'sku'=>'REFERENCE-'.str_pad((string)($index+1),2,'0',STR_PAD_LEFT),'mrp'=>self::price($p['old_price_display']),'price'=>self::price($p['price_display']),'description'=>'Reference catalog presentation for the local design preview. Company-approved product details will replace this content.','unit'=>'pack','stock'=>1,'preview'=>true];
        },$fixture['products'],array_keys($fixture['products']));
    }
    public static function price(string $display):string { return Money::decimal(Money::cents(str_replace(',','',preg_replace('/[^0-9.,]/','',$display)))); }
    public static function find(string $id):?array { foreach(self::items() as $p)if($p['id']===$id)return $p;return null; }
}
