<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Proof that a patient agreed to be kept on file: the prescriber confirmed it, or the visitor
 * ticked the consent line on their consultation request.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescriber_id
 * @property string $source
 * @property string $statement
 * @property int|null $consultation_request_id
 * @property Carbon $confirmed_at
 */
class PrescriptionPatientConsent extends Model
{
    public const SourcePrescriber = 'prescriber_confirmed';

    public const SourcePublicRequest = 'public_request';

    protected $fillable = ['patient_id', 'prescriber_id', 'source', 'statement', 'consultation_request_id', 'confirmed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['confirmed_at' => 'datetime'];
    }

    /** @return BelongsTo<PrescriptionPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PrescriptionPatient::class, 'patient_id');
    }
}
