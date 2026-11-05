<?php

namespace App\Models;

use Database\Factories\PartnerAgreementVersionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One published version of the dealer agreement, in Bangla and English.
 * Rows are read-only once saved: editing the text publishes a new version.
 */
class PartnerAgreementVersion extends Model
{
    /** @use HasFactory<PartnerAgreementVersionFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'published_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    /** @return HasMany<PartnerAgreementAcceptance, $this> */
    public function acceptances(): HasMany
    {
        return $this->hasMany(PartnerAgreementAcceptance::class);
    }

    /** The text in the given language, falling back to Bangla. */
    public function bodyFor(string $locale): string
    {
        return $locale === 'en' ? $this->body_en : $this->body_bn;
    }
}
