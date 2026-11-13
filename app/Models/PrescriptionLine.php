<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One medicine on a prescription. A line with a `product_id` is a company product; a line
 * without one was typed by hand, which the owners' switch (PrescriptionSettings) must allow.
 * `mrp` is set only on a clinic prescription's product lines, the only lines that leave stock.
 *
 * @property int $id
 * @property int $prescription_id
 * @property int|null $product_id
 * @property string $medicine_name
 * @property string|null $strength
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
 * @property string|null $dose_schedule
 * @property string|null $food_timing
 * @property int|null $duration_count
 * @property string|null $duration_unit
 */
class PrescriptionLine extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'prescription_id', 'product_id', 'medicine_name', 'strength', 'dose', 'frequency', 'duration', 'instructions',
        'unit_price', 'quantity', 'discount', 'line_total', 'mrp', 'position',
        'dose_schedule', 'food_timing', 'duration_count', 'duration_unit',
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
            'duration_count' => 'integer',
        ];
    }

    /**
     * The fields a template or a repeated prescription carries over to a new form.
     *
     * @return array<string, mixed>
     */
    public function toDraft(): array
    {
        return [
            'product_id' => $this->product_id,
            'medicine_name' => $this->medicine_name,
            'strength' => $this->strength,
            'dose' => $this->dose,
            'frequency' => $this->frequency,
            'duration' => $this->duration,
            'instructions' => $this->instructions,
            'dose_schedule' => $this->dose_schedule,
            'food_timing' => $this->food_timing,
            'duration_count' => $this->duration_count,
            'duration_unit' => $this->duration_unit,
            'unit_price' => (string) $this->unit_price,
            'quantity' => (int) $this->quantity,
            'discount' => (string) $this->discount,
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
