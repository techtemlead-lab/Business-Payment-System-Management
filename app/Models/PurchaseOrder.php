<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const StatusDraft = 'draft';

    public const StatusSent = 'sent';

    public const StatusPartlyReceived = 'partly_received';

    public const StatusReceived = 'received';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Supplier, $this> */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /** @return BelongsTo<CompanyBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<PurchaseOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderLine::class);
    }

    /** @return HasMany<GoodsReceipt, $this> */
    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /**
     * The order number people quote to a supplier.
     */
    public function displayNumber(): string
    {
        return 'PO-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    /**
     * Goods can only arrive once the order has gone to the supplier and is not finished.
     */
    public function isAwaitingGoods(): bool
    {
        return in_array($this->status, [self::StatusSent, self::StatusPartlyReceived], true);
    }
}
