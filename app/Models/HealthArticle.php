<?php

namespace App\Models;

use App\Domain\Content\PublicTrustContent;
use Database\Factories\HealthArticleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A health article the owner writes for the public website.
 */
class HealthArticle extends Model
{
    /** @use HasFactory<HealthArticleFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(fn () => PublicTrustContent::forgetCounts());
        static::deleted(fn () => PublicTrustContent::forgetCounts());
    }

    /**
     * @param  Builder<HealthArticle>  $query
     * @return Builder<HealthArticle>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** The first part of the article, for the list page. */
    public function excerpt(int $length = 220): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $this->body));

        return mb_strlen($text) > $length ? mb_substr($text, 0, $length).'…' : $text;
    }
}
