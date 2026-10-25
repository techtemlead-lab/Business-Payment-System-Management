<?php

namespace App\Domain\Accounts;

use App\Models\User;
use App\Support\Audit;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PartnerKycDocuments
{
    /** @var list<string> */
    public const Kinds = [
        'nid_front',
        'nid_back',
        'birth_certificate',
        'agreement',
        'photo',
        'other',
    ];

    /**
     * @return list<array{
     *   id: string,
     *   kind: string,
     *   original_name: string,
     *   mime: string,
     *   size_bytes: int,
     *   note: ?string,
     *   uploaded_by: int,
     *   created_at: string
     * }>
     */
    public function listFor(User $partner): array
    {
        return DB::table('kyc_documents')
            ->where('user_id', $partner->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get(['id', 'kind', 'original_name', 'mime', 'size_bytes', 'note', 'uploaded_by', 'created_at'])
            ->map(fn ($row) => [
                'id' => (string) $row->id,
                'kind' => (string) $row->kind,
                'original_name' => (string) $row->original_name,
                'mime' => (string) $row->mime,
                'size_bytes' => (int) $row->size_bytes,
                'note' => $row->note !== null ? (string) $row->note : null,
                'uploaded_by' => (int) $row->uploaded_by,
                'created_at' => (string) $row->created_at,
            ])
            ->all();
    }

    /**
     * @return array{id: string, kind: string, original_name: string}
     */
    public function store(User $actor, User $partner, string $kind, UploadedFile $file, ?string $note = null): array
    {
        if (! in_array($partner->role, ['dealer', 'sub_dealer'], true)) {
            throw ValidationException::withMessages(['user' => 'KYC scans apply only to partner accounts.']);
        }
        if (! in_array($kind, self::Kinds, true)) {
            throw ValidationException::withMessages(['kind' => 'Choose a valid document kind.']);
        }

        $id = (string) Str::uuid();
        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin'));
        $path = $file->storeAs('kyc-documents/'.$partner->id, $id.'.'.$extension, 'local');

        try {
            DB::table('kyc_documents')->insert([
                'id' => $id,
                'user_id' => $partner->id,
                'kind' => $kind,
                'disk_path' => $path,
                'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
                'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
                'uploaded_by' => $actor->id,
                'note' => $note !== null && trim($note) !== '' ? Str::limit(trim($note), 500, '') : null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        Audit::record($actor->id, 'account.kyc_document_uploaded', (string) $partner->id, [
            'document_id' => $id,
            'kind' => $kind,
            'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
        ]);

        return [
            'id' => $id,
            'kind' => $kind,
            'original_name' => Str::limit((string) $file->getClientOriginalName(), 255, ''),
        ];
    }

    public function download(User $partner, string $documentId): StreamedResponse
    {
        $row = DB::table('kyc_documents')
            ->where('id', $documentId)
            ->where('user_id', $partner->id)
            ->first();
        abort_unless($row && Storage::disk('local')->exists($row->disk_path), 404);

        return Storage::disk('local')->download(
            $row->disk_path,
            $row->original_name,
            [
                'Content-Type' => $row->mime,
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
