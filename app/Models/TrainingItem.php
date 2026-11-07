<?php

namespace App\Models;

use Database\Factories\TrainingItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One lesson in the partner training library: a video (a link or an uploaded file), a PDF guide or a
 * plain-text article. Partners only see published items meant for their role.
 *
 * @property int $id
 * @property string $title_bn
 * @property string $title_en
 * @property string|null $summary
 * @property string $type
 * @property string $topic
 * @property string $audience
 * @property string|null $video_url
 * @property string|null $video_path
 * @property string|null $video_name
 * @property int|null $video_size
 * @property string|null $video_mime
 * @property string|null $body
 * @property string|null $pdf_path
 * @property string|null $pdf_name
 * @property int|null $pdf_size
 * @property int $sort_order
 * @property bool $published
 * @property int $quiz_pass_percent
 * @property Carbon|null $published_at
 */
class TrainingItem extends Model
{
    /** @use HasFactory<TrainingItemFactory> */
    use HasFactory;

    public const Types = ['video', 'pdf', 'article'];

    public const Topics = ['products', 'selling', 'rules', 'portal'];

    public const Audiences = ['all', 'dealers', 'sub_dealers'];

    /** Items first published within this many days carry a "new" badge. */
    public const NewForDays = 14;

    protected $fillable = [
        'title_bn', 'title_en', 'summary', 'type', 'topic', 'audience', 'video_url', 'video_path', 'video_name', 'video_size', 'video_mime', 'body',
        'pdf_path', 'pdf_name', 'pdf_size', 'sort_order', 'published', 'published_at', 'created_by', 'updated_by', 'quiz_pass_percent',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'published' => 'boolean',
            'published_at' => 'datetime',
            'sort_order' => 'integer',
            'pdf_size' => 'integer',
            'video_size' => 'integer',
            'quiz_pass_percent' => 'integer',
        ];
    }

    /**
     * The quiz questions, in the order partners see them.
     *
     * @return HasMany<TrainingQuizQuestion, $this>
     */
    public function quizQuestions(): HasMany
    {
        return $this->hasMany(TrainingQuizQuestion::class)->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Published items that a partner with the given role may see.
     *
     * @param  Builder<TrainingItem>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->where('published', true)->whereIn('audience', self::audiencesFor($user));
    }

    /**
     * Audience values that include the given user.
     *
     * @return list<string>
     */
    public static function audiencesFor(User $user): array
    {
        return match ($user->role) {
            'dealer' => ['all', 'dealers'],
            'sub_dealer' => ['all', 'sub_dealers'],
            default => [],
        };
    }

    public function isVisibleTo(User $user): bool
    {
        return $this->published && in_array($this->audience, self::audiencesFor($user), true);
    }

    public function isNew(): bool
    {
        return $this->published_at !== null && $this->published_at->greaterThanOrEqualTo(now()->subDays(self::NewForDays));
    }
}
