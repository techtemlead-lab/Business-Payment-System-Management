<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasUuids;

    protected $guarded = ['id'];

    protected $attributes = ['paid' => '0.00', 'due_credit' => '0.00'];

    protected $hidden = ['idempotency_key', 'payload_hash', 'commission_policy_snapshot'];

    protected function casts(): array
    {
        return ['is_opening_balance' => 'boolean', 'total' => 'decimal:2', 'paid' => 'decimal:2', 'due_credit' => 'decimal:2', 'commission_policy_snapshot' => 'array'];
    }

    public function outstanding(): string
    {
        return Money::decimal(Money::cents($this->total) - Money::cents($this->paid) - Money::cents($this->due_credit ?? '0.00'));
    }

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fulfillmentBranch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'fulfillment_branch_id');
    }

    public function paymentClaims(): HasMany
    {
        return $this->hasMany(PaymentClaim::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(Shipment::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(CommissionAllocation::class);
    }
}
