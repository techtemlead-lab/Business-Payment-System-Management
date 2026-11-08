<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockTransfer extends Model
{
    public const StatusRequested = 'requested';

    public const StatusInTransit = 'in_transit';

    public const StatusReceived = 'received';

    public const StatusCancelled = 'cancelled';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'integer',
            'sent_at' => 'datetime',
            'received_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<CompanyBranch, $this> */
    public function sourceBranch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'source_branch_id');
    }

    /** @return BelongsTo<CompanyBranch, $this> */
    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'destination_branch_id');
    }
}
