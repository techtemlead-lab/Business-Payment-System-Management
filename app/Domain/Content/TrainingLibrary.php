<?php

namespace App\Domain\Content;

use App\Models\TrainingCompletion;
use App\Models\TrainingItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Who may manage or open training items, what each screen shows, and how
 * items are saved. PDF guides live on the private disk and are only streamed
 * through the controller after the checks here.
 */
final class TrainingLibrary
{
    /** Private disk folder for uploaded PDF guides. */
    public const PdfFolder = 'training';

    public function __construct(private TrainingQuiz $quiz) {}

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
            'body' => $item->type === 'article' ? $item->body : null,
            'pdf_url' => $item->type === 'pdf' && $item->pdf_path ? '/training/'.$item->id.'/pdf' : null,
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
    public function save(User $actor, ?TrainingItem $item, array $data, ?UploadedFile $pdf): TrainingItem
    {
        $oldPdf = $item?->pdf_path;
        $newPdf = $pdf ? $pdf->store(self::PdfFolder, 'local') : null;
        $type = (string) $data['type'];

        try {
            $saved = $this->persist($actor, $item, $data, $pdf, $newPdf, $type);
        } catch (\Throwable $exception) {
            if ($newPdf) {
                Storage::disk('local')->delete($newPdf);
            }
            throw $exception;
        }

        if ($oldPdf && $oldPdf !== $saved->pdf_path) {
            Storage::disk('local')->delete($oldPdf);
        }

        return $saved;
    }

    /** @param array<string, mixed> $data */
    private function persist(User $actor, ?TrainingItem $item, array $data, ?UploadedFile $pdf, ?string $newPdf, string $type): TrainingItem
    {
        return DB::transaction(function () use ($actor, $item, $data, $pdf, $newPdf, $type): TrainingItem {
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
                'video_url' => $type === 'video' ? trim((string) $data['video_url']) : null,
                'body' => $type === 'article' ? trim((string) $data['body']) : null,
                'updated_by' => $actor->id,
            ]);
            if (isset($data['sort_order'])) {
                $item->sort_order = (int) $data['sort_order'];
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
        $pdf = $item->pdf_path;
        $id = $item->id;
        $title = $item->title_en;
        $item->delete();
        if ($pdf) {
            Storage::disk('local')->delete($pdf);
        }
        Audit::record($actor->id, 'training.deleted', (string) $id, ['title' => $title]);
    }

    /** A plain ASCII file name for the download header, always ending in .pdf. */
    public function safePdfName(string $original): string
    {
        $base = pathinfo($original, PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base), '-.');

        return substr($base !== '' ? $base : 'training-guide', 0, 150).'.pdf';
    }
}
