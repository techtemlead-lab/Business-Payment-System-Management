<?php

namespace App\Http\Controllers;

use App\Domain\Accounts\PartnerCertificates;
use App\Domain\Content\TrainingLibrary;
use App\Domain\Content\TrainingQuiz;
use App\Domain\Content\TrainingVideoLink;
use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Office editor for the partner training library, and the private PDF and video streams.
 * EnforceStaffActions also checks the staff "content" permission on every change.
 */
class TrainingController extends Controller
{
    public function __construct(private TrainingLibrary $library, private TrainingQuiz $quiz) {}

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate($this->rules($request, null));
        $this->library->save($request->user(), null, $data, $request->file('pdf'), $request->file('video_file'));

        return back()->with('success', __('portal.training_flash_saved'));
    }

    public function update(Request $request, TrainingItem $trainingItem): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate($this->rules($request, $trainingItem));
        $this->library->save($request->user(), $trainingItem, $data, $request->file('pdf'), $request->file('video_file'));

        return back()->with('success', __('portal.training_flash_saved'));
    }

    public function publish(Request $request, TrainingItem $trainingItem): RedirectResponse
    {
        $this->authorizeManager($request);
        $published = $request->validate(['published' => 'required|boolean'])['published'];
        $this->library->setPublished($request->user(), $trainingItem, (bool) $published);

        return back()->with('success', __($published ? 'portal.training_flash_published' : 'portal.training_flash_unpublished'));
    }

    public function reorder(Request $request): RedirectResponse
    {
        $this->authorizeManager($request);
        $ids = $request->validate([
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'required|integer|distinct|exists:training_items,id',
        ])['ids'];
        $this->library->reorder($request->user(), array_map('intval', $ids));

        return back()->with('success', __('portal.training_flash_reordered'));
    }

    public function destroy(Request $request, TrainingItem $trainingItem): RedirectResponse
    {
        $this->authorizeManager($request);
        $this->library->delete($request->user(), $trainingItem);

        return back()->with('success', __('portal.training_flash_deleted'));
    }

    /** Saves the item's quiz: the pass mark and the full list of questions (an empty list removes the quiz). */
    public function saveQuiz(Request $request, TrainingItem $trainingItem): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $request->validate([
            'pass_percent' => 'required|integer|min:1|max:100',
            'questions' => 'present|array|max:'.TrainingQuiz::MaxQuestions,
            'questions.*.question' => 'required|string|max:500',
            'questions.*.options' => 'required|array|min:'.TrainingQuiz::MinOptions.'|max:'.TrainingQuiz::MaxOptions,
            'questions.*.options.*' => 'required|string|max:200',
            'questions.*.correct_option' => 'required|integer|min:0',
        ]);
        foreach ($data['questions'] as $index => $question) {
            if ((int) $question['correct_option'] >= count($question['options'])) {
                throw ValidationException::withMessages(['questions.'.$index.'.correct_option' => __('portal.training_quiz_err_correct')]);
            }
        }
        $this->quiz->save($request->user(), $trainingItem, (int) $data['pass_percent'], array_values($data['questions']));

        return back()->with('success', __('portal.training_quiz_flash_saved'));
    }

    /** A dealer or sub-dealer sends their quiz answers; the result comes back as a message. */
    public function submitQuiz(Request $request, TrainingItem $trainingItem): RedirectResponse
    {
        $partner = $request->user();
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true) && $partner->status === 'active', 403);
        abort_unless($trainingItem->isVisibleTo($partner), 404);
        $answers = $request->validate([
            'answers' => 'required|array|max:'.TrainingQuiz::MaxQuestions,
            'answers.*' => 'nullable|integer|min:0|max:'.(TrainingQuiz::MaxOptions - 1),
        ])['answers'];
        $result = $this->quiz->submit($partner, $trainingItem, $answers);
        $message = __($result['passed'] ? 'portal.training_quiz_flash_passed' : 'portal.training_quiz_flash_failed', [
            'score' => (string) $result['score'],
            'correct' => (string) $result['correct'],
            'total' => (string) $result['total'],
            'pass' => (string) $trainingItem->quiz_pass_percent,
        ]);

        return back()->with($result['passed'] ? 'success' : 'error', $message);
    }

    /** Printable completion certificate for a partner who passed the item's quiz. */
    public function certificate(Request $request, TrainingItem $trainingItem): BinaryFileResponse
    {
        $partner = $request->user();
        abort_unless(in_array($partner->role, ['dealer', 'sub_dealer'], true), 403);
        $completion = TrainingCompletion::query()
            ->where('training_item_id', $trainingItem->id)
            ->where('user_id', $partner->id)
            ->whereNotNull('passed_at')
            ->first();
        abort_unless($completion, 404);

        return app(PartnerCertificates::class)->trainingPdf($partner, $trainingItem, $completion);
    }

    /** Streams a PDF guide to a signed-in partner or staff member who may read it; ?download=1 saves it. */
    public function pdf(Request $request, TrainingItem $trainingItem): StreamedResponse|BinaryFileResponse
    {
        abort_unless($trainingItem->type === 'pdf' && filled($trainingItem->pdf_path), 404);
        abort_unless($this->library->canOpen($request->user(), $trainingItem), 404);

        return $this->fileResponse($request, (string) $trainingItem->pdf_path, $trainingItem->pdf_name ?: 'training-guide.pdf', 'application/pdf');
    }

    /** Plays an uploaded training video (seeking works) or, with ?download=1, saves it. */
    public function video(Request $request, TrainingItem $trainingItem): StreamedResponse|BinaryFileResponse
    {
        abort_unless($trainingItem->type === 'video' && filled($trainingItem->video_path), 404);
        abort_unless($this->library->canOpen($request->user(), $trainingItem), 404);
        $mime = in_array($trainingItem->video_mime, TrainingLibrary::VideoMimes, true) ? (string) $trainingItem->video_mime : 'video/mp4';

        return $this->fileResponse($request, (string) $trainingItem->video_path, $trainingItem->video_name ?: 'training-video.mp4', $mime);
    }

    /**
     * A private file from the training disk. Local disks answer byte ranges so a video can be skipped
     * forward and back; other disks stream the whole file.
     */
    private function fileResponse(Request $request, string $path, string $name, string $mime): StreamedResponse|BinaryFileResponse
    {
        $disk = TrainingLibrary::disk();
        abort_unless($disk->exists($path), 404);
        $disposition = $request->boolean('download') ? ResponseHeaderBag::DISPOSITION_ATTACHMENT : ResponseHeaderBag::DISPOSITION_INLINE;
        $headers = [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ];
        if (config('filesystems.disks.'.TrainingLibrary::diskName().'.driver') === 'local') {
            return response()->file($disk->path($path), $headers)->setContentDisposition($disposition, $name);
        }

        return $disk->response($path, $name, $headers, $disposition);
    }

    private function authorizeManager(Request $request): void
    {
        abort_unless($this->library->canManage($request->user()), 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(Request $request, ?TrainingItem $existing): array
    {
        $needsPdf = $existing === null || $existing->type !== 'pdf' || blank($existing->pdf_path);
        // A video needs a link or an uploaded file, unless it already has an uploaded file.
        $hasVideoFile = $existing !== null && $existing->type === 'video' && filled($existing->video_path);
        $videoKilobytes = TrainingLibrary::videoMaxMb() * 1024;
        $pdfKilobytes = TrainingLibrary::pdfMaxMb() * 1024;

        return [
            'title_bn' => 'required|string|max:200',
            'title_en' => 'required|string|max:200',
            'summary' => 'nullable|string|max:500',
            'type' => ['required', Rule::in(TrainingItem::Types)],
            'topic' => ['required', Rule::in(TrainingItem::Topics)],
            'audience' => ['required', Rule::in(TrainingItem::Audiences)],
            'video_url' => [
                ...($hasVideoFile ? [] : [Rule::requiredIf(fn (): bool => $request->input('type') === 'video' && ! $request->hasFile('video_file'))]),
                'nullable', 'string', 'max:500', 'url:https',
                function (string $attribute, mixed $value, Closure $fail) use ($request): void {
                    if ($request->input('type') === 'video' && ! $request->hasFile('video_file') && ! TrainingVideoLink::isAllowed((string) $value)) {
                        $fail(__('portal.training_err_video_host'));
                    }
                },
            ],
            'video_file' => [
                'nullable', 'file',
                'mimes:'.implode(',', TrainingLibrary::VideoExtensions),
                'mimetypes:'.implode(',', TrainingLibrary::VideoMimes),
                'max:'.$videoKilobytes,
            ],
            'pdf' => [...($needsPdf ? ['required_if:type,pdf'] : []), 'nullable', 'file', 'mimes:pdf', 'mimetypes:application/pdf', 'max:'.$pdfKilobytes],
            'body' => 'nullable|required_if:type,article|string|max:20000',
            'sort_order' => 'nullable|integer|min:0|max:100000',
        ];
    }
}
