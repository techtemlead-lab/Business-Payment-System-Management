<?php

namespace App\Domain\Content;

use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Who may manage or open training items, what each screen shows, and how
 * items are saved. PDF guides and uploaded videos live on the training disk
 * (config madina.training.disk, private by default) and are only streamed
 * through the controller after the checks here.
 */
final class TrainingLibrary
{
    /** Disk folder for uploaded PDF guides. */
    public const PdfFolder = 'training';

    /** Disk folder for uploaded training videos. */
    public const VideoFolder = 'training/videos';

    /** Video files staff may upload. */
    public const VideoExtensions = ['mp4', 'webm'];

    public const VideoMimes = ['video/mp4', 'video/webm'];

    public function __construct(private TrainingQuiz $quiz) {}

    /** The disk training files are kept on. */
    public static function diskName(): string
    {
        return (string) config('madina.training.disk', 'local');
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    /** Largest uploaded video, in megabytes. */
    public static function videoMaxMb(): int
    {
        return max(1, (int) config('madina.training.video_max_mb', 100));
    }

    /** Largest PDF guide, in megabytes. */
    public static function pdfMaxMb(): int
    {
        return max(1, (int) config('madina.training.pdf_max_mb', 20));
    }

    /** Office staff with the content permission and the owners manage the library. */
    public function canManage(User $user): bool
    {
        return $user->status === 'active' && ($user->owner() || $user->allows('content'));
    }

    /** Managers open any PDF; other staff open published ones; partners only published ones meant for them. */
    public function canOpen(User $user, TrainingItem $item): bool
    {
        if ($user->status !== 'active') {
            return false;
        }
        if ($this->canManage($user)) {
            return true;
        }
        if ($user->staff()) {
            return $item->published;
        }

        return $item->isVisibleTo($user);
    }

    /**
     * Published items for a dealer or sub-dealer, grouped by topic in the fixed topic order.
     *
     * @return list<array{topic: string, items: list<array<string, mixed>>}>
     */
    public function partnerGroups(User $user): array
    {
        $items = TrainingItem::query()
            ->visibleTo($user)
            ->with('quizQuestions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
        $completions = $this->quiz->completionsFor($user, $items->pluck('id')->all());

        $groups = [];
        foreach (TrainingItem::Topics as $topic) {
            $inTopic = $items->where('topic', $topic)->values();
            if ($inTopic->isNotEmpty()) {
                $groups[] = [
                    'topic' => $topic,
                    'items' => $inTopic->map(fn (TrainingItem $item): array => $this->present($item, false, $completions->get($item->id)))->all(),
                ];
            }
        }

        return $groups;
    }

    /**
     * Every item, drafts included, in display order for the office editor.
     *
     * @return list<array<string, mixed>>
     */
    public function staffItems(): array
    {
        return TrainingItem::query()
            ->with('quizQuestions')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->map(fn (TrainingItem $item): array => $this->present($item, true))
            ->all();
    }

    /**
     * Screen data for one item. The raw video link is only shown to staff.
     *
     * @return array<string, mixed>
     */
    public function present(TrainingItem $item, bool $forStaff, ?TrainingCompletion $completion = null): array
    {
        $quiz = $this->quiz->present($item, $forStaff);

        return [
            'id' => $item->id,
            'title_bn' => $item->title_bn,
            'title_en' => $item->title_en,
            'summary' => $item->summary,
            'type' => $item->type,
            'topic' => $item->topic,
            'audience' => $item->audience,
            'embed_url' => $item->type === 'video' && $item->video_url ? TrainingVideoLink::embedUrl($item->video_url) : null,
            'provider' => $item->type === 'video' && $item->video_url ? TrainingVideoLink::provider($item->video_url) : null,
            // An uploaded video plays in the page and can be downloaded.
            'video_file_url' => $item->type === 'video' && $item->video_path ? '/training/'.$item->id.'/video' : null,
            'video_download_url' => $item->type === 'video' && $item->video_path ? '/training/'.$item->id.'/video?download=1' : null,
            'video_name' => $item->type === 'video' ? $item->video_name : null,
            'video_size' => $item->type === 'video' ? $item->video_size : null,
            'video_mime' => $item->type === 'video' ? $item->video_mime : null,
            'body' => $item->type === 'article' ? $item->body : null,
            'pdf_url' => $item->type === 'pdf' && $item->pdf_path ? '/training/'.$item->id.'/pdf' : null,
            'pdf_download_url' => $item->type === 'pdf' && $item->pdf_path ? '/training/'.$item->id.'/pdf?download=1' : null,
            'pdf_name' => $item->type === 'pdf' ? $item->pdf_name : null,
            'pdf_size' => $item->type === 'pdf' ? $item->pdf_size : null,
            'is_new' => $item->isNew(),
            'published_at' => $item->published_at?->toIso8601String(),
            'quiz' => $quiz,
            ...(! $forStaff && $quiz !== null ? ['progress' => [
                'completed' => $completion?->passed_at !== null,
                'completed_at' => $completion?->passed_at?->toIso8601String(),
                'attempts' => (int) ($completion?->attempts ?? 0),
                'last_score' => $completion?->attempts ? $completion->last_score : null,
                'certificate_url' => $completion?->passed_at !== null ? '/training/'.$item->id.'/certificate' : null,
            ]] : []),
            ...($forStaff ? [
                'video_url' => $item->video_url,
                'published' => $item->published,
                'sort_order' => $item->sort_order,
                'updated_at' => $item->updated_at?->toIso8601String(),
            ] : []),
        ];
    }

    /**
     * Create or update an item from validated form data.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(User $actor, ?TrainingItem $item, array $data, ?UploadedFile $pdf, ?UploadedFile $video = null): TrainingItem
    {
        $oldPdf = $item?->pdf_path;
        $oldVideo = $item?->video_path;
        $type = (string) $data['type'];
        $newPdf = $pdf && $type === 'pdf' ? $pdf->store(self::PdfFolder, self::diskName()) : null;
        $newVideo = $video && $type === 'video' ? $video->store(self::VideoFolder, self::diskName()) : null;

        try {
            $saved = $this->persist($actor, $item, $data, $pdf, $newPdf, $type, $video, $newVideo);
        } catch (\Throwable $exception) {
            foreach (array_filter([$newPdf, $newVideo]) as $path) {
                self::disk()->delete($path);
            }
            throw $exception;
        }

        if ($oldPdf && $oldPdf !== $saved->pdf_path) {
            self::disk()->delete($oldPdf);
        }
        if ($oldVideo && $oldVideo !== $saved->video_path) {
            self::disk()->delete($oldVideo);
        }

        return $saved;
    }

    /** @param array<string, mixed> $data */
    private function persist(User $actor, ?TrainingItem $item, array $data, ?UploadedFile $pdf, ?string $newPdf, string $type, ?UploadedFile $video, ?string $newVideo): TrainingItem
    {
        return DB::transaction(function () use ($actor, $item, $data, $pdf, $newPdf, $type, $video, $newVideo): TrainingItem {
            $item ??= new TrainingItem([
                'created_by' => $actor->id,
                'sort_order' => ((int) TrainingItem::query()->max('sort_order')) + 1,
                'published' => false,
            ]);
            $item->fill([
                'title_bn' => trim((string) $data['title_bn']),
                'title_en' => trim((string) $data['title_en']),
                'summary' => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
                'type' => $type,
                'topic' => $data['topic'],
                'audience' => $data['audience'],
                'body' => $type === 'article' ? trim((string) $data['body']) : null,
                'updated_by' => $actor->id,
            ]);
            if (isset($data['sort_order'])) {
                $item->sort_order = (int) $data['sort_order'];
            }
            $link = trim((string) ($data['video_url'] ?? ''));
            $noVideoFile = ['video_path' => null, 'video_name' => null, 'video_size' => null, 'video_mime' => null];
            if ($type !== 'video') {
                $item->fill(['video_url' => null, ...$noVideoFile]);
            } elseif ($video && $newVideo) {
                // An uploaded file replaces a link.
                $item->fill([
                    'video_url' => null,
                    'video_path' => $newVideo,
                    'video_name' => $this->safeFileName($video->getClientOriginalName(), 'training-video', (string) ($video->guessExtension() ?: 'mp4')),
                    'video_size' => (int) $video->getSize(),
                    'video_mime' => (string) $video->getMimeType(),
                ]);
            } elseif ($link !== '') {
                // A link replaces an uploaded file.
                $item->fill(['video_url' => $link, ...$noVideoFile]);
            }
            if ($type !== 'pdf') {
                $item->fill(['pdf_path' => null, 'pdf_name' => null, 'pdf_size' => null]);
            } elseif ($pdf && $newPdf) {
                $item->fill([
                    'pdf_path' => $newPdf,
                    'pdf_name' => $this->safePdfName($pdf->getClientOriginalName()),
                    'pdf_size' => (int) $pdf->getSize(),
                ]);
            }
            $creating = ! $item->exists;
            $item->save();
            Audit::record($actor->id, $creating ? 'training.created' : 'training.updated', (string) $item->id, [
                'title' => $item->title_en,
                'type' => $item->type,
                'audience' => $item->audience,
            ]);

            return $item;
        });
    }

    public function setPublished(User $actor, TrainingItem $item, bool $published): void
    {
        $item->forceFill([
            'published' => $published,
            'published_at' => $published ? ($item->published_at ?? now()) : $item->published_at,
            'updated_by' => $actor->id,
        ])->save();
        Audit::record($actor->id, $published ? 'training.published' : 'training.unpublished', (string) $item->id);
    }

    /**
     * Put items in the given order; items not listed keep their place after them.
     *
     * @param  list<int>  $ids
     */
    public function reorder(User $actor, array $ids): void
    {
        DB::transaction(function () use ($actor, $ids): void {
            $position = 1;
            foreach ($ids as $id) {
                TrainingItem::query()->whereKey($id)->update(['sort_order' => $position++, 'updated_by' => $actor->id]);
            }
            TrainingItem::query()->whereNotIn('id', $ids)->orderBy('sort_order')->orderBy('id')->get(['id'])
                ->each(function (TrainingItem $item) use (&$position): void {
                    TrainingItem::query()->whereKey($item->id)->update(['sort_order' => $position++]);
                });
            Audit::record($actor->id, 'training.reordered', 'training', ['order' => $ids]);
        });
    }

    public function delete(User $actor, TrainingItem $item): void
    {
        $files = array_filter([$item->pdf_path, $item->video_path]);
        $id = $item->id;
        $title = $item->title_en;
        $item->delete();
        foreach ($files as $path) {
            self::disk()->delete($path);
        }
        Audit::record($actor->id, 'training.deleted', (string) $id, ['title' => $title]);
    }

    /** A plain ASCII file name for the download header, always ending in .pdf. */
    public function safePdfName(string $original): string
    {
        return $this->safeFileName($original, 'training-guide', 'pdf');
    }

    /** A plain ASCII file name for the download header, with the given extension. */
    public function safeFileName(string $original, string $fallback, string $extension): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return substr($base !== '' ? $base : $fallback, 0, 150).'.'.$extension;
    }
}
