<?php

namespace App\Domain\Orders;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The screenshot or PDF a dealer attaches to prove a payment. Stored on the
 * private disk (never public), with its type and fingerprint so finance can
 * trust it was not swapped later.
 */
final class PaymentProof
{
    /** Validation for an attached proof: an image or PDF up to 5 MB. */
    public const Rules = 'file|mimes:jpg,jpeg,png,webp,pdf|max:5120';

    /** @return array{proof_path: string, proof_mime: string, proof_sha256: string} */
    public function store(UploadedFile $file): array
    {
        $extension = $file->guessExtension() ?: 'bin';
        $path = $file->storeAs('payment-proofs', Str::uuid().'.'.$extension, 'local');

        return [
            'proof_path' => $path,
            'proof_mime' => (string) $file->getMimeType(),
            'proof_sha256' => hash_file('sha256', $file->getRealPath()),
        ];
    }

    /** Removes a stored proof when the claim it belonged to was not saved. */
    public function discard(?array $stored): void
    {
        if ($stored !== null) {
            Storage::disk('local')->delete($stored['proof_path']);
        }
    }
}
