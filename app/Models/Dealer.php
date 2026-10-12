<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Dealer extends Model
{
    use HasFactory;

    public function isAreaDealer(): bool
    {
        return $this->dealer_type === 'area_dealer';
    }

    protected $guarded = ['id', 'user_id', 'referrer_id', 'dealership_request_id', 'referral_verified_by', 'referral_verified_at'];

    protected function casts(): array
    {
        return ['referrer_id' => 'integer', 'dealership_request_id' => 'integer', 'referral_verified_by' => 'integer', 'referral_verified_at' => 'datetime'];
    }

    public function referrer(): BelongsTo
    {
        return $this->belongsTo(self::class, 'referrer_id');
    }

    public function referralVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'referral_verified_by');
    }

    public function referrals(): HasMany
    {
        return $this->hasMany(self::class, 'referrer_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(LedgerEntry::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
