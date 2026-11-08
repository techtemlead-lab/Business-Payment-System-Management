<?php

namespace App\Models;

use App\Domain\Content\PublicTrustContent;
use Database\Factories\QualityDocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A licence, certificate or lab report the owner shows on the public quality page.
 */
class QualityDocument extends Model
{
    /** @use HasFactory<QualityDocumentFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'is_published' => 'boolean',
        'size_bytes' => 'integer',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => PublicTrustContent::forgetCounts());
        static::deleted(fn () => PublicTrustContent::forgetCounts());
    }

    /**
     * @param  Builder<QualityDocument>  $query
     * @return Builder<QualityDocument>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('is_published', true);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime_type, 'image/');
    }
}
