<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A partner's quiz result on one training item. passed_at is set the first time
 * they reach the pass mark, and stays set after later attempts.
 *
 * @property int $id
 * @property int $training_item_id
 * @property int $user_id
 * @property int $attempts
 * @property int $last_score
 * @property int $best_score
 * @property Carbon|null $passed_at
 */
class TrainingCompletion extends Model
{
    protected $fillable = ['training_item_id', 'user_id', 'attempts', 'last_score', 'best_score', 'passed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'last_score' => 'integer',
            'best_score' => 'integer',
            'passed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TrainingItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
