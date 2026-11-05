<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentClaim extends Model
{
    protected $guarded = [];

    /**
     * Compatibility mutator: payment_claims belongs to orders (which holds user_id).
     * Silently ignore redundant user_id assignment to prevent database column errors.
     */
    public function setUserIdAttribute(mixed $value): void
    {
        // No-op: user_id is referenced through order relationship.
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
