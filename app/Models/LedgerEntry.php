<?php

namespace App\Models;

use App\Services\EarningsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class LedgerEntry extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount_minor' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::creating(function (self $entry): void {
            if ($entry->earning_category !== null && ! array_key_exists($entry->earning_category, EarningsService::CATEGORIES)) {
                throw ValidationException::withMessages(['earning_category' => 'Choose a supported earnings category.']);
            }
            if ($entry->kind === 'earned' && $entry->earning_category === 'area_dealer_commission' && ! Dealer::findOrFail($entry->dealer_id)->isAreaDealer()) {
                throw ValidationException::withMessages(['earning_category' => 'Area dealer commission requires a company-approved area dealer.']);
            }
        });
        static::updating(fn () => throw new \LogicException('Posted ledger entries are immutable.'));
        static::deleting(fn () => throw new \LogicException('Use an auditable reversal instead of deleting entries.'));
    }
}
