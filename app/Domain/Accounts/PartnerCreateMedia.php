<?php

namespace App\Domain\Accounts;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

final class PartnerCreateMedia
{
    public function __construct(private PartnerKycDocuments $documents) {}

    /**
     * Optional photo + documents during partner account creation.
     *
     * @param  array<string, UploadedFile|null>  $files  keyed by KYC kind
     * @return list<array{id: string, kind: string}>
     */
    public function attachOptional(User $actor, User $partner, array $files): array
    {
        $stored = [];
        foreach ($files as $kind => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }
            if (! in_array($kind, PartnerKycDocuments::Kinds, true)) {
                continue;
            }
            $row = $this->documents->store($actor, $partner, $kind, $file, 'Attached during account creation');
            $stored[] = $row;
            if ($kind === 'photo') {
                $this->publishProfilePhoto($partner, $file, $row['id']);
            }
        }

        return $stored;
    }

    private function publishProfilePhoto(User $partner, UploadedFile $file, string $documentId): void
    {
        $extension = strtolower((string) ($file->guessExtension() ?: 'jpg'));
        $publicPath = $file->storeAs('partner-photos/'.$partner->id, $documentId.'.'.$extension, 'public');
        $url = Storage::disk('public')->url($publicPath);
        $profile = is_array($partner->profile) ? $partner->profile : [];
        $profile['photo_url'] = $url;
        $profile['avatar_url'] = $url;
        $partner->forceFill(['profile' => $profile])->save();
    }
}
