<?php

namespace App\Models;

use App\Support\ProductVideo;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['cost_price', 'image_path', 'video_path', 'video_mime'];

    protected $attributes = ['stock' => 0, 'low_stock_at' => 10, 'active' => true, 'tone' => 'sage', 'unit' => 'pack'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_stock_paused' => 'boolean', 'registry_version' => 'integer', 'featured' => 'boolean', 'mrp' => 'decimal:2', 'dealer_price' => 'decimal:2', 'sub_dealer_price' => 'decimal:2', 'cost_price' => 'decimal:2'];
    }

    public function stockStatus(): string
    {
        return $this->is_stock_paused || $this->stock <= 0 ? 'out_of_stock'
            : ($this->stock <= $this->low_stock_at ? 'low_stock' : 'in_stock');
    }

    public function media(): array
    {
        $youtube = $this->video_source === 'youtube' && preg_match('/^[A-Za-z0-9_-]{11}$/D', $this->video_youtube_id ?? '') ? 'https://www.youtube-nocookie.com/embed/'.$this->video_youtube_id : null;
        $vimeo = $this->video_source === 'vimeo' && preg_match('/^[0-9]{6,12}$/D', $this->video_vimeo_id ?? '')
            ? 'https://player.vimeo.com/video/'.$this->video_vimeo_id.'?dnt=1'.($this->video_vimeo_hash && preg_match('/^[a-fA-F0-9]{6,64}$/D', $this->video_vimeo_hash) ? '&h='.$this->video_vimeo_hash : '') : null;
        $video = $this->video_source === 'upload' && $this->video_path ? route('products.video', $this->id, false).'?v='.$this->registry_version : null;
        if ($this->video_source === 'url') {
            $video = ProductVideo::direct($this->video_external_url ?? '');
        }

        return ['image_url' => $this->image_path ? route('products.image', $this->id, false).'?v='.$this->registry_version : null,
            'video_url' => $video, 'youtube_embed_url' => $youtube, 'video_embed_url' => $youtube ?? $vimeo];
    }
}
