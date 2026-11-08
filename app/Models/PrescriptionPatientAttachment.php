<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lab report or old prescription on a patient. Only the patient's own prescriber can see it.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescriber_id
 * @property string $kind
 * @property string $disk_path
 * @property string $original_name
 * @property string $mime
 * @property int $size_bytes
 */
class PrescriptionPatientAttachment extends Model
{
    protected $fillable = ['patient_id', 'prescriber_id', 'kind', 'disk_path', 'original_name', 'mime', 'size_bytes'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['original_name' => 'encrypted', 'size_bytes' => 'integer'];
    }

    /** @return BelongsTo<PrescriptionPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PrescriptionPatient::class, 'patient_id');
    }
}
