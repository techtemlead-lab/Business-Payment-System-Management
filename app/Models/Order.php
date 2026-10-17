<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Order extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['paid' => '0.00', 'due_credit' => '0.00'];

    protected $hidden = ['idempotency_key', 'payload_hash', 'commission_policy_snapshot'];

    protected function casts(): array
    {
        return ['total' => 'decimal:2', 'paid' => 'decimal:2', 'due_credit' => 'decimal:2', 'commission_policy_snapshot' => 'array'];
    }

    public function outstanding(): string
    {
        return Money::decimal(Money::cents($this->total) - Money::cents($this->paid) - Money::cents($this->due_credit ?? '0.00'));
    }

    public function lines()
    {
        return $this->hasMany(OrderLine::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
