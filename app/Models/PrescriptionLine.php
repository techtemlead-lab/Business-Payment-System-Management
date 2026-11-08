<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medicine on a prescription. `product_id` and `mrp` are set only on clinic
 * prescriptions; partner lines are typed by hand and never linked to stock.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int|null $product_id
 * @property string $medicine_name
 * @property string|null $dose
 * @property string|null $frequency
 * @property string|null $duration
 * @property string|null $instructions
 * @property string $unit_price
 * @property int $quantity
 * @property string $discount
 * @property string $line_total
 * @property string|null $mrp
 * @property int $position
 */
class PrescriptionLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prescription_id', 'product_id', 'medicine_name', 'dose', 'frequency', 'duration', 'instructions',
        'unit_price', 'quantity', 'discount', 'line_total', 'mrp', 'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'line_total' => 'decimal:2',
            'mrp' => 'decimal:2',
            'quantity' => 'integer',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<Prescription, $this> */
    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
