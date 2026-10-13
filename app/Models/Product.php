<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
class Product extends Model {
    use HasFactory;
    protected $guarded=['id'];
    protected $hidden=['cost_price','image_path','video_path','video_mime'];
    protected $attributes=['stock'=>0,'low_stock_at'=>10,'active'=>true,'tone'=>'sage','unit'=>'pack'];
    protected function casts(): array { return ['active'=>'boolean','registry_version'=>'integer','featured'=>'boolean','mrp'=>'decimal:2','dealer_price'=>'decimal:2','cost_price'=>'decimal:2']; }
    public function media():array {return ['image_url'=>$this->image_path?route('products.image',$this->id,false).'?v='.$this->registry_version:null,'video_url'=>$this->video_source==='upload'&&$this->video_path?route('products.video',$this->id,false).'?v='.$this->registry_version:null,'youtube_embed_url'=>$this->video_source==='youtube'&&preg_match('/^[A-Za-z0-9_-]{11}$/D',$this->video_youtube_id??'')?'https://www.youtube-nocookie.com/embed/'.$this->video_youtube_id:null];}
}
