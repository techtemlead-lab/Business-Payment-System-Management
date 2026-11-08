<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\StockLotFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One received batch of a product at one branch. The lots of a product and branch always
 * add up to its branch_product_stocks row; only StockLots may change the quantity.
 *
 * @property CarbonInterface|null $expires_on
 * @property CarbonInterface|null $received_on
 * @property CarbonInterface|null $held_at
 */
class StockLot extends Model
{
    /** @use HasFactory<StockLotFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expires_on' => 'date',
            'received_on' => 'date',
            'quantity' => 'integer',
            'received_quantity' => 'integer',
            'unit_cost' => 'decimal:2',
            'near_expiry_noticed_at' => 'datetime',
            'expired_noticed_at' => 'datetime',
            'held_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<CompanyBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function heldBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'held_by');
    }

    /** Today's date at the company, which is when a lot's expiry is judged. */
    public static function today(): string
    {
        return CarbonImmutable::now((string) config('madina.business_timezone', 'Asia/Dhaka'))->toDateString();
    }

    /**
     * Earliest expiry first; lots with no expiry date go last, and the oldest receipt
     * breaks a tie.
     *
     * @param  Builder<StockLot>  $query
     */
    #[Scope]
    protected function fefo(Builder $query): void
    {
        $query->orderByRaw('expires_on ASC NULLS LAST')->orderBy('received_on')->orderBy('id');
    }

    /**
     * Lots that may still leave the branch for a customer. A lot is good through its expiry date
     * and for as long as it is not on hold.
     *
     * @param  Builder<StockLot>  $query
     */
    #[Scope]
    protected function usable(Builder $query): void
    {
        $query->whereNull('held_at')->where(fn (Builder $lots) => $lots->whereNull('expires_on')->orWhereDate('expires_on', '>=', self::today()));
    }

    public function isOnHold(): bool
    {
        return $this->held_at !== null;
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->toDateString() < self::today();
    }
}
