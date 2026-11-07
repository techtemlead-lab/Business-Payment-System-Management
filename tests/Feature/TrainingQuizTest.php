<?php

namespace Tests\Feature;

use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TrainingQuizTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_content_staff_write_a_quiz_and_partners_never_see_the_answers(): void
    {
        $editor = User::factory()->staff('editor')->create();
        $item = TrainingItem::factory()->published()->create();

        $this->actingAs($editor)->onPortal('manage')->put('/commands/training/'.$item->id.'/quiz', [
            'pass_percent' => 60,
            'questions' => $this->questions(),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(60, $item->fresh()->quiz_pass_percent);
        $this->assertSame(['Yes', 'No', 'Sometimes'], $item->quizQuestions()->first()->options);

        $this->actingAs($editor)->onPortal('manage')->get('/training')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.training.items.0.quiz.pass_percent', 60)
                ->where('data.training.items.0.quiz.questions.0.correct_option', 1));

        $dealer = User::factory()->dealer()->create(['status' => 'active']);
        $this->actingAs($dealer)->onPortal('dealer')->get('/training')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('data.training.groups.0.items.0.quiz.questions', 2)
                ->missing('data.training.groups.0.items.0.quiz.questions.0.correct_option')
                ->where('data.training.groups.0.items.0.progress.completed', false));

        $this->actingAs($editor)->onPortal('manage')->put('/commands/training/'.$item->id.'/quiz', [
            'pass_percent' => 60,
            'questions' => [['question' => 'Bad', 'options' => ['A', 'B'], 'correct_option' => 3]],
        ])->assertSessionHasErrors('questions.0.correct_option');

        $this->actingAs($dealer)->onPortal('dealer')->put('/commands/training/'.$item->id.'/quiz', [
            'pass_percent' => 10,
            'questions' => [],
        ])->assertForbidden();
        $this->assertSame(2, $item->quizQuestions()->count());

        $this->actingAs($editor)->onPortal('manage')->put('/commands/training/'.$item->id.'/quiz', ['pass_percent' => 60, 'questions' => []])
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $item->quizQuestions()->count(), 'An empty list turns the quiz off.');
    }

    public function test_reaching_the_pass_mark_completes_the_item_and_a_later_low_score_keeps_it(): void
    {
        [$item, $questions] = $this->quizItem(60);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 0, $questions[1] => 0],
        ])->assertRedirect()->assertSessionHas('error');
        $completion = TrainingCompletion::query()->sole();
        $this->assertNull($completion->passed_at);
        $this->assertSame(0, $completion->last_score);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 1, $questions[1] => 2],
        ])->assertRedirect()->assertSessionHas('success');
        $completion->refresh();
        $this->assertNotNull($completion->passed_at);
        $this->assertSame(100, $completion->best_score);
        $this->assertSame(2, $completion->attempts);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 0, $questions[1] => 0],
        ])->assertRedirect();
        $completion->refresh();
        $this->assertNotNull($completion->passed_at, 'A completed lesson stays complete.');
        $this->assertSame(0, $completion->last_score);
        $this->assertSame(100, $completion->best_score);

        $this->actingAs($dealer)->onPortal('dealer')->get('/training')->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.training.groups.0.items.0.progress.completed', true)
                ->where('data.training.groups.0.items.0.progress.certificate_url', '/training/'.$item->id.'/certificate'));
    }

    public function test_every_question_must_be_answered_and_hidden_items_cannot_be_attempted(): void
    {
        [$item, $questions] = $this->quizItem(50);
        $dealer = User::factory()->dealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 1],
        ])->assertSessionHasErrors('answers');
        $this->assertSame(0, TrainingCompletion::query()->count());

        $subDealersOnly = TrainingItem::factory()->published()->forAudience('sub_dealers')->create();
        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$subDealersOnly->id.'/quiz-attempt', [
            'answers' => [1 => 0],
        ])->assertNotFound();

        $staff = User::factory()->staff('editor')->create();
        $this->actingAs($staff)->onPortal('manage')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 1, $questions[1] => 2],
        ])->assertForbidden();
    }

    public function test_certificate_is_only_for_partners_who_passed(): void
    {
        [$item, $questions] = $this->quizItem(50);
        $dealer = User::factory()->dealer()->create(['status' => 'active', 'dealer_code' => 'MNHC-T1']);
        $other = User::factory()->subDealer()->create(['status' => 'active']);

        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/certificate')->assertNotFound();

        $this->actingAs($dealer)->onPortal('dealer')->post('/commands/training/'.$item->id.'/quiz-attempt', [
            'answers' => [$questions[0] => 1, $questions[1] => 2],
        ]);

        $this->actingAs($dealer)->onPortal('dealer')->get('/training/'.$item->id.'/certificate')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->actingAs($other)->onPortal('subdealer')->get('/training/'.$item->id.'/certificate')->assertNotFound();
    }

    /**
     * A published lesson for everyone with a two-question quiz.
     *
     * @return array{0: TrainingItem, 1: list<int>}
     */
    private function quizItem(int $passPercent): array
    {
        $item = TrainingItem::factory()->published()->create(['quiz_pass_percent' => $passPercent]);
        $ids = [];
        foreach ($this->questions() as $position => $question) {
            $ids[] = $item->quizQuestions()->create([...$question, 'sort_order' => $position + 1])->id;
        }

        return [$item, $ids];
    }

    /** @return list<array{question: string, options: list<string>, correct_option: int}> */
    private function questions(): array
    {
        return [
            ['question' => 'Can a dealer sell above MRP?', 'options' => ['Yes', 'No', 'Sometimes'], 'correct_option' => 1],
            ['question' => 'Who sends the goods?', 'options' => ['The dealer', 'The sub-dealer', 'The company'], 'correct_option' => 2],
        ];
    }
}
