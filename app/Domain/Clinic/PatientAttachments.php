<?php

namespace App\Domain\Clinic;

use App\Models\PrescriptionPatient;
use App\Models\PrescriptionPatientAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Lab reports and old prescriptions on a patient. Files stay on the private disk and leave
 * only through download(), for the patient's own prescriber.
 */
final class PatientAttachments
{
    public const Kinds = ['lab_report', 'old_prescription', 'other'];

    public const AllowedTypes = 'pdf,jpg,jpeg,png,webp';

    public const MaxKilobytes = 10240;

    /** Most files one patient may hold. */
    public const MaxPerPatient = 50;

    private const Disk = 'local';

    public function __construct(private PatientRecords $records) {}

    /**
     * @return list<array{id: int, kind: string, name: string, mime: string, size_bytes: int, created_at: string|null}>
     */
    public function listFor(PrescriptionPatient $patient): array
    {
        return PrescriptionPatientAttachment::query()
            ->where('patient_id', $patient->id)
            ->orderByDesc('id')
            ->limit(self::MaxPerPatient)
            ->get()
            ->map(fn (PrescriptionPatientAttachment $attachment): array => [
                'id' => $attachment->id,
                'kind' => $attachment->kind,
                'name' => $attachment->original_name,
                'mime' => $attachment->mime,
                'size_bytes' => $attachment->size_bytes,
                'created_at' => $attachment->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** The caller has already checked ownership and validated the file's type and size. */
    public function store(User $prescriber, PrescriptionPatient $patient, string $kind, UploadedFile $file): PrescriptionPatientAttachment
    {
        if (PrescriptionPatientAttachment::query()->where('patient_id', $patient->id)->count() >= self::MaxPerPatient) {
            throw ValidationException::withMessages(['file' => __('clinic_records.err_attachment_limit', ['max' => self::MaxPerPatient])]);
        }
        $storedName = Str::uuid().'.'.strtolower((string) $file->guessExtension());
        $path = $file->storeAs('clinic-attachments/'.$prescriber->id.'/'.$patient->id, $storedName, self::Disk);
        if ($path === false) {
            throw ValidationException::withMessages(['file' => __('clinic_records.err_attachment_failed')]);
        }

        try {
            return PrescriptionPatientAttachment::query()->create([
                'patient_id' => $patient->id,
                'prescriber_id' => $prescriber->id,
                'kind' => $kind,
                'disk_path' => $path,
                'original_name' => $this->displayName($file),
                'mime' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
                'size_bytes' => (int) $file->getSize(),
            ]);
        } catch (\Throwable $exception) {
            Storage::disk(self::Disk)->delete($path);
            throw $exception;
        }
    }

    /** Always a download, never shown in the page, and written to the access log. */
    public function download(User $viewer, PrescriptionPatient $patient, PrescriptionPatientAttachment $attachment): StreamedResponse
    {
        abort_unless((int) $attachment->patient_id === (int) $patient->id, 404);
        $this->records->open($viewer, $patient, PatientAccessLog::FileDownloaded);
        abort_unless(Storage::disk(self::Disk)->exists($attachment->disk_path), 404);

        // Plain-ASCII fallback: a Bangla file name has no usable one and would otherwise crash the response.
        $fallback = 'attachment-'.$attachment->id.'.'.pathinfo($attachment->disk_path, PATHINFO_EXTENSION);
        $response = Storage::disk(self::Disk)->download($attachment->disk_path, $fallback, [
            'Content-Type' => $attachment->mime,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->headers->set('Content-Disposition', HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $attachment->original_name, $fallback));

        return $response;
    }

    public function delete(User $prescriber, PrescriptionPatient $patient, PrescriptionPatientAttachment $attachment): void
    {
        abort_unless((int) $attachment->patient_id === (int) $patient->id, 404);
        $this->records->assertOwn($prescriber, $patient);
        $attachment->delete();
        Storage::disk(self::Disk)->delete($attachment->disk_path);
    }

    /** The name to show and offer on download, without characters a download header can't carry. */
    private function displayName(UploadedFile $file): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F\/\\\\]+/u', '_', $file->getClientOriginalName()));

        return $name === '' ? 'file' : mb_substr($name, -200);
    }
}
