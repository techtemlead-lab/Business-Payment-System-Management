<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One multiple-choice question in a training item's quiz. Options are stored in order;
 * correct_option is the position (0-based) of the right answer.
 *
 * @property int $id
 * @property int $training_item_id
 * @property string $question
 * @property array<int, string> $options
 * @property int $correct_option
 * @property int $sort_order
 */
class TrainingQuizQuestion extends Model
{
    protected $fillable = ['training_item_id', 'question', 'options', 'correct_option', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'options' => 'array',
            'correct_option' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<TrainingItem, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(TrainingItem::class, 'training_item_id');
    }
}
