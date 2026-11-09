<?php

namespace App\Domain\Content;

use App\Models\SalesKitItem;
use App\Models\User;
use App\Support\Audit;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The approved sales kit. Only the owner adds, edits or removes files; partners view and download them.
 * Files stay on the private training disk and are only streamed to signed-in partners and owners.
 */
final class SalesKit
{
    public const Folder = 'sales-kit';

    public const MaxKilobytes = 10240;

    /** @var list<string> */
    public const Extensions = ['pdf', 'png', 'jpg', 'jpeg', 'webp'];

    /** @var list<string> */
    public const Mimes = ['application/pdf', 'image/png', 'image/jpeg', 'image/webp'];

    public static function disk(): Filesystem
    {
        return Storage::disk(TrainingLibrary::diskName());
    }

    public static function canManage(User $user): bool
    {
        return $user->owner();
    }

    public static function canView(User $user): bool
    {
        return $user->owner() || in_array($user->role, ['dealer', 'sub_dealer'], true);
    }

    /** @return array{can_manage: bool, kinds: list<string>, languages: list<string>, items: list<array<string, mixed>>} */
    public function forViewer(User $user): array
    {
        return [
            'can_manage' => self::canManage($user),
            'kinds' => SalesKitItem::Kinds,
            'languages' => SalesKitItem::Languages,
            'items' => SalesKitItem::query()->latest()->orderByDesc('id')->limit(500)->get()
                ->map(function (SalesKitItem $item): array {
                    $version = $this->version($item);

                    return [
                        'id' => (int) $item->id,
                        'title' => (string) $item->title,
                        'kind' => (string) $item->kind,
                        'language' => (string) $item->language,
                        'caption' => $item->caption,
                        'file_name' => (string) $item->file_name,
                        'mime' => (string) $item->mime,
                        'size' => (int) $item->size,
                        'is_image' => str_starts_with((string) $item->mime, 'image/'),
                        'view_url' => '/sales-kit/'.$item->id.'/file?inline=1'.($version === null ? '' : '&v='.$version),
                        'download_url' => '/sales-kit/'.$item->id.'/file'.($version === null ? '' : '?v='.$version),
                    ];
                })->all(),
        ];
    }

    /** @param array{title: string, kind: string, language: string, caption?: string|null} $data */
    public function add(User $owner, array $data, UploadedFile $file): SalesKitItem
    {
        abort_unless(self::canManage($owner), 403);
        $path = $this->storeFile($file);
        $item = SalesKitItem::query()->create([
            'title' => $data['title'],
            'kind' => $data['kind'],
            'language' => $data['language'],
            'caption' => $data['caption'] ?? null,
            'file_path' => $path,
            'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
            'mime' => (string) $file->getMimeType(),
            'size' => (int) $file->getSize(),
            'uploaded_by' => $owner->id,
        ]);
        Audit::record($owner->id, 'sales_kit.added', (string) $item->id, ['title' => $item->title, 'kind' => $item->kind]);

        return $item;
    }

    /**
     * Saves an edit, and swaps the file when a new one comes with it. The old file is only
     * deleted once the new one is saved, so a failure can never leave partners with nothing.
     *
     * @param  array{title: string, kind: string, language: string, caption?: string|null, reason: string}  $data
     */
    public function update(User $owner, SalesKitItem $item, array $data, ?UploadedFile $file): SalesKitItem
    {
        abort_unless(self::canManage($owner), 403);
        $newPath = $file === null ? null : $this->storeFile($file);
        $oldPath = null;
        try {
            DB::transaction(function () use ($owner, $item, $data, $file, $newPath, &$oldPath): void {
                $locked = SalesKitItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
                $oldPath = $locked->file_path;
                $before = $locked->only(['title', 'kind', 'language', 'caption', 'file_name']);
                $locked->update([
                    'title' => $data['title'],
                    'kind' => $data['kind'],
                    'language' => $data['language'],
                    'caption' => $data['caption'] ?? null,
                    ...($file === null ? [] : [
                        'file_path' => $newPath,
                        'file_name' => mb_substr($file->getClientOriginalName(), 0, 200),
                        'mime' => (string) $file->getMimeType(),
                        'size' => (int) $file->getSize(),
                    ]),
                ]);
                $changes = array_intersect_key($locked->getChanges(), $before);
                Audit::record($owner->id, 'sales_kit.updated', (string) $locked->id, [
                    'title' => $locked->title,
                    'fields' => array_keys($changes),
                    'before' => array_intersect_key($before, $changes),
                    'after' => $changes,
                    'file_replaced' => $file !== null,
                    'reason' => $data['reason'],
                ]);
            });
        } catch (Throwable $failure) {
            if ($newPath !== null) {
                self::disk()->delete($newPath);
            }
            throw $failure;
        }
        if ($newPath !== null) {
            self::disk()->delete($oldPath);
        }

        return $item->refresh();
    }

    public function remove(User $owner, SalesKitItem $item): void
    {
        abort_unless(self::canManage($owner), 403);
        self::disk()->delete($item->file_path);
        Audit::record($owner->id, 'sales_kit.removed', (string) $item->id, ['title' => $item->title]);
        $item->delete();
    }

    /**
     * The file itself. Only images and PDFs can be stored, so opening one in the browser is safe.
     */
    public function response(User $viewer, SalesKitItem $item, bool $inline): StreamedResponse
    {
        abort_unless(self::canView($viewer), 403);
        $disk = self::disk();
        abort_unless($disk->exists($item->file_path), 404);

        return $disk->download($item->file_path, $item->file_name, [
            'Content-Type' => $item->mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.addslashes($item->file_name).'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function storeFile(UploadedFile $file): string
    {
        $path = $file->store(self::Folder, TrainingLibrary::diskName());
        if ($path === false) {
            abort(500);
        }

        return $path;
    }

    /**
     * Browsers keep a file for five minutes (see response()), so a changed item gets a new address
     * and a partner never sees the old file after it is replaced.
     */
    private function version(SalesKitItem $item): ?int
    {
        return $item->updated_at?->gt($item->created_at) ? $item->updated_at->getTimestamp() : null;
    }
}
