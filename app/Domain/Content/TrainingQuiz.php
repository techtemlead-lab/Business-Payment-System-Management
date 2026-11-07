<?php

namespace App\Domain\Content;

use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\TrainingQuizQuestion;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
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

    /** Default number of tries a partner gets per quiz in one hour (owner can change it). */
    public const DefaultAttemptsPerHour = 5;

    public const MaxAttemptsPerHour = 50;

    private const LimitSetting = 'training_quiz_limit';

    /**
     * How many tries a partner gets per quiz in one hour, so the right answers cannot be
     * found by guessing again and again.
     *
     * @return array{per_hour: int, version: int}
     */
    public function attemptLimit(): array
    {
        $row = DB::table('business_settings')->where('key', self::LimitSetting)->first();
        $stored = $row ? json_decode((string) $row->value, true) : [];
        $perHour = (int) (is_array($stored) ? ($stored['per_hour'] ?? self::DefaultAttemptsPerHour) : self::DefaultAttemptsPerHour);

        return ['per_hour' => max(1, min(self::MaxAttemptsPerHour, $perHour)), 'version' => (int) ($row->version ?? 0)];
    }

    /** Owners change the hourly limit; the change is audited. */
    public function saveAttemptLimit(User $actor, int $perHour, int $version): void
    {
        abort_unless($actor->owner() && $actor->status === 'active', 403);
        DB::transaction(function () use ($actor, $perHour, $version): void {
            $stored = DB::table('business_settings')->where('key', self::LimitSetting)->lockForUpdate()->first();
            $before = $this->attemptLimit();
            if ($version !== $before['version']) {
                throw ValidationException::withMessages(['per_hour' => __('portal.training_quiz_limit_err_stale')]);
            }
            DB::table('business_settings')->updateOrInsert(['key' => self::LimitSetting], [
                'value' => json_encode(['per_hour' => $perHour], JSON_THROW_ON_ERROR), 'version' => $version + 1,
                'updated_by' => $actor->id, 'created_at' => $stored->created_at ?? now(), 'updated_at' => now(),
            ]);
            Audit::record($actor->id, 'training.quiz_limit_changed', self::LimitSetting, ['before' => $before['per_hour'], 'after' => $perHour]);
        });
    }

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
        $limitKey = 'training-quiz|'.$partner->id.'|'.$item->id;
        $perHour = $this->attemptLimit()['per_hour'];
        if (RateLimiter::tooManyAttempts($limitKey, $perHour)) {
            $minutes = max(1, (int) ceil(RateLimiter::availableIn($limitKey) / 60));
            throw ValidationException::withMessages(['answers' => __('portal.training_quiz_err_too_many', ['count' => (string) $perHour, 'minutes' => (string) $minutes])]);
        }
        $missing = $questions->first(fn (TrainingQuizQuestion $question): bool => ! isset($answers[$question->id]) || ! is_numeric($answers[$question->id]));
        if ($missing) {
            throw ValidationException::withMessages(['answers' => __('portal.training_quiz_err_unanswered')]);
        }
        RateLimiter::hit($limitKey, 3600);
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
