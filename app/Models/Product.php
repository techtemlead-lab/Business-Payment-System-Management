<?php

namespace App\Models;

use App\Domain\Inventory\SellableStock;
use App\Support\Money;
use App\Support\ProductVideo;
use App\Support\SiteCustomization;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class Product extends Model
{
    use HasFactory;

    /**
     * Columns in other tables that hold a product's id. A row in any of them means the product is
     * in use, so it is archived and not deleted: the database would refuse the delete, or would
     * quietly remove or empty that row. Every foreign key to products must be listed here.
     *
     * @var array<string, string> table name => column name
     */
    public const ReferencingColumns = [
        'branch_product_stocks' => 'product_id',
        'dated_schemes' => 'free_product_id',
        'goods_receipt_lines' => 'product_id',
        'order_lines' => 'product_id',
        'prescription_lines' => 'product_id',
        'product_concern_product' => 'product_id',
        'purchase_order_lines' => 'product_id',
        'site_feedback' => 'product_id',
        'stock_count_lines' => 'product_id',
        'stock_lot_movements' => 'product_id',
        'stock_lots' => 'product_id',
        'stock_movements' => 'product_id',
        'stock_transfers' => 'product_id',
    ];

    protected $guarded = ['id'];

    protected $hidden = ['cost_price', 'image_path', 'image_original_path', 'video_path', 'video_mime', 'sellable_stock'];

    protected $attributes = ['stock' => 0, 'low_stock_at' => 10, 'active' => true, 'tone' => 'sage', 'unit' => 'pack'];

    protected static function booted(): void
    {
        $bust = static fn () => SiteCustomization::forgetCache();
        static::saved($bust);
        static::deleted($bust);
        // A product on sale is never archived, however it got back on sale.
        static::saving(function (Product $product): void {
            if ($product->active && $product->archived_at !== null) {
                $product->archived_at = null;
            }
        });
    }

    /** Taken off sale by Remove. A product that was never published is a draft, not archived. */
    public function isArchived(): bool
    {
        return ! $this->active && $this->archived_at !== null;
    }

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
            'archived_at' => 'datetime',
            'is_stock_paused' => 'boolean',
            'registry_version' => 'integer',
            'featured' => 'boolean',
            'featured_position' => 'integer',
            'mrp' => 'decimal:2',
            'dealer_price' => 'decimal:2',
            'sub_dealer_price' => 'decimal:2',
            'cost_price' => 'decimal:2',
            'expires_on' => 'date',
        ];
    }

    public function subcategory(): BelongsTo
    {
        return $this->belongsTo(ProductSubcategory::class, 'product_subcategory_id');
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class, 'product_unit_id');
    }

    /**
     * @return BelongsToMany<ProductConcern, $this>
     */
    public function concerns(): BelongsToMany
    {
        return $this->belongsToMany(ProductConcern::class, ProductConcern::PivotTable);
    }

    public function branchStocks(): HasMany
    {
        return $this->hasMany(BranchProductStock::class);
    }

    /**
     * Adds each product's sellable units to its row, so a list does not ask once per product.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function withSellableStock(Builder $query): void
    {
        if ($query->getQuery()->columns === null) {
            $query->select('products.*');
        }
        $query->selectRaw(SellableStock::sql().' AS sellable_stock', SellableStock::bindings());
    }

    /**
     * The products on the home page, in the order the owner set.
     *
     * @param  Builder<Product>  $query
     */
    #[Scope]
    protected function featuredInOrder(Builder $query): void
    {
        $query->where('active', true)
            ->where('featured', true)
            ->orderByRaw('featured_position ASC NULLS LAST')
            ->orderBy('name');
    }

    /** @param  Builder<Product>  $query */
    #[Scope]
    protected function whereSellable(Builder $query): void
    {
        $query->whereRaw(SellableStock::sql().' > 0', SellableStock::bindings());
    }

    /** @param  Builder<Product>  $query */
    #[Scope]
    protected function whereNotSellable(Builder $query): void
    {
        $query->whereRaw(SellableStock::sql().' <= 0', SellableStock::bindings());
    }

    /** @param  Builder<Product>  $query */
    #[Scope]
    protected function orderBySellable(Builder $query, string $direction = 'asc'): void
    {
        $query->orderByRaw(SellableStock::sql().($direction === 'desc' ? ' DESC' : ' ASC'), SellableStock::bindings());
    }

    /**
     * Units an order can really be given today: not on hold, not expired, at a working stock hub.
     * The stock column stays the physical count for the stock screens.
     */
    public function sellableStock(): int
    {
        if (array_key_exists('sellable_stock', $this->attributes)) {
            return (int) $this->attributes['sellable_stock'];
        }
        if (! $this->exists) {
            return max(0, (int) $this->stock);
        }

        return (int) self::query()->whereKey($this->getKey())->withSellableStock()->value('sellable_stock');
    }

    public function stockStatus(): string
    {
        if ($this->is_stock_paused) {
            return 'out_of_stock';
        }
        $sellable = $this->sellableStock();

        return $sellable <= 0 ? 'out_of_stock' : ($sellable <= $this->low_stock_at ? 'low_stock' : 'in_stock');
    }

    /** Whether any other record still points at this product, by foreign key or in a saved list. */
    public function isReferenced(): bool
    {
        foreach (self::ReferencingColumns as $table => $column) {
            if (DB::table($table)->where($column, $this->id)->exists()) {
                return true;
            }
        }

        return DB::table('dated_schemes')->whereJsonContains('product_ids', $this->id)->exists()
            || DB::table('prescription_templates')->whereJsonContains('lines', [['product_id' => $this->id]])->exists();
    }

    public function registerName(): string
    {
        $name = trim((string) $this->name);
        $strength = trim((string) ($this->strength ?? ''));
        if ($strength === '' || str_contains(mb_strtolower($name), mb_strtolower($strength))) {
            return $name;
        }

        return $name.'-'.$strength;
    }

    public function discountPercentFromRetail(mixed $channelPrice): ?string
    {
        if ($channelPrice === null || $channelPrice === '' || $this->mrp === null || $this->mrp === '') {
            return null;
        }

        try {
            $retail = Money::cents((string) $this->mrp);
            $net = Money::cents((string) $channelPrice);
        } catch (InvalidArgumentException) {
            return null;
        }

        if ($retail <= 0 || $net < 0 || $net > $retail) {
            return null;
        }

        $points = intdiv(($retail - $net) * 10000 + intdiv($retail, 2), $retail);

        return intdiv($points, 100).'.'.str_pad((string) ($points % 100), 2, '0', STR_PAD_LEFT);
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
