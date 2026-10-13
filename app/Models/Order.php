<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
class Order extends Model {
    use HasUuids;
    protected $guarded=['id'];
    protected $hidden=['idempotency_key','payload_hash'];
    protected function casts(): array { return ['total'=>'decimal:2','paid'=>'decimal:2']; }
    public function lines() { return $this->hasMany(OrderLine::class); }
    public function user() { return $this->belongsTo(User::class); }
}
