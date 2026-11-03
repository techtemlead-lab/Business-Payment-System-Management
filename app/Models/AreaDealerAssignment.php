<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AreaDealerAssignment extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::creating(function (self $assignment): void {
            $assignment->releaseInactiveTerritoryKey();
        });
    }

    protected $casts = [
        'monthly_quota' => 'decimal:2',
        'current_month_purchase' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function placeCode(): BelongsTo
    {
        return $this->belongsTo(PlaceCode::class);
    }

    /**
     * Inactive seats must not keep the unique territory key, or the next
     * dealer assigned to that place fails the unique constraint.
     */
    public function releaseInactiveTerritoryKey(): void
    {
        $key = $this->territory_key;
        if (! is_string($key) || $key === '') {
            return;
        }

        static::query()
            ->where('territory_key', $key)
            ->where('is_active', false)
            ->update([
                'territory_key' => null,
                'updated_at' => now(),
            ]);
    }
}
