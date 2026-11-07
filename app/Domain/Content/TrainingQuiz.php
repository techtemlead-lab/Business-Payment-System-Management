<?php

namespace App\Domain\Content;

use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\TrainingQuizQuestion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Optional multiple-choice quiz on a training item. Office staff write the questions;
 * a dealer or sub-dealer who scores at least the pass mark has completed the item.
 * The right answers never leave the server for partners.
 */
final class TrainingQuiz
{
    public const MaxQuestions = 20;

    public const MinOptions = 2;

    public const MaxOptions = 4;

    /**
     * Replace the item's quiz. An empty question list removes the quiz.
     *
     * @param  array<int, array{question: string, options: array<int, string>, correct_option: int}>  $questions
     */
    public function save(User $actor, TrainingItem $item, int $passPercent, array $questions): void
    {
        DB::transaction(function () use ($actor, $item, $passPercent, $questions): void {
            $item->forceFill(['quiz_pass_percent' => $passPercent, 'updated_by' => $actor->id])->save();
            $item->quizQuestions()->delete();
            foreach (array_values($questions) as $position => $question) {
                $options = array_values(array_map(fn ($option): string => trim((string) $option), $question['options']));
                TrainingQuizQuestion::query()->create([
                    'training_item_id' => $item->id,
                    'question' => trim((string) $question['question']),
                    'options' => $options,
                    'correct_option' => (int) $question['correct_option'],
                    'sort_order' => $position + 1,
                ]);
            }
            Audit::record($actor->id, 'training.quiz_saved', (string) $item->id, [
                'questions' => count($questions),
                'pass_percent' => $passPercent,
            ]);
        });
    }

    /**
     * Mark a partner's answers. Reaching the pass mark completes the item; a later
     * lower score never takes the completion away.
     *
     * @param  array<int|string, int|string|null>  $answers  question id => chosen option position
     * @return array{score: int, correct: int, total: int, passed: bool, completed: bool}
     */
    public function submit(User $partner, TrainingItem $item, array $answers): array
    {
        $questions = $item->quizQuestions()->get();
        if ($questions->isEmpty()) {
            throw ValidationException::withMessages(['answers' => __('portal.training_quiz_err_none')]);
        }
        $missing = $questions->first(fn (TrainingQuizQuestion $question): bool => ! isset($answers[$question->id]) || ! is_numeric($answers[$question->id]));
        if ($missing) {
            throw ValidationException::withMessages(['answers' => __('portal.training_quiz_err_unanswered')]);
        }
        $correct = $questions->filter(fn (TrainingQuizQuestion $question): bool => (int) $answers[$question->id] === $question->correct_option)->count();
        $total = $questions->count();
        $score = (int) floor($correct * 100 / $total);
        $passed = $score >= $item->quiz_pass_percent;

        $completion = DB::transaction(function () use ($partner, $item, $score, $passed): TrainingCompletion {
            $completion = TrainingCompletion::query()->lockForUpdate()->firstOrNew([
                'training_item_id' => $item->id,
                'user_id' => $partner->id,
            ]);
            $completion->forceFill([
                'attempts' => $completion->attempts + 1,
                'last_score' => $score,
                'best_score' => max((int) $completion->best_score, $score),
                'passed_at' => $completion->passed_at ?? ($passed ? now() : null),
            ])->save();

            return $completion;
        });
        Audit::record($partner->id, 'training.quiz_attempted', (string) $item->id, ['score' => $score, 'passed' => $passed]);

        return ['score' => $score, 'correct' => $correct, 'total' => $total, 'passed' => $passed, 'completed' => $completion->passed_at !== null];
    }

    /**
     * The partner's results for the given items, keyed by item id.
     *
     * @param  list<int>  $itemIds
     * @return Collection<int, TrainingCompletion>
     */
    public function completionsFor(User $partner, array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return TrainingCompletion::query()
            ->where('user_id', $partner->id)
            ->whereIn('training_item_id', $itemIds)
            ->get()
            ->keyBy('training_item_id');
    }

    /**
     * Quiz data for the screen. Staff see the right answers; partners do not.
     *
     * @return array{pass_percent: int, questions: list<array<string, mixed>>}|null
     */
    public function present(TrainingItem $item, bool $forStaff): ?array
    {
        $questions = $item->relationLoaded('quizQuestions') ? $item->quizQuestions : $item->quizQuestions()->get();
        if ($questions->isEmpty() && ! $forStaff) {
            return null;
        }

        return [
            'pass_percent' => $item->quiz_pass_percent,
            'questions' => $questions->map(fn (TrainingQuizQuestion $question): array => [
                'id' => $question->id,
                'question' => $question->question,
                'options' => array_values($question->options ?? []),
                ...($forStaff ? ['correct_option' => $question->correct_option] : []),
            ])->all(),
        ];
    }
}
