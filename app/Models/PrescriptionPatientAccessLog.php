<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One opening of a patient record, or one refused try. Only the patient's own prescriber
 * reads these.
 *
 * @property int $id
 * @property int $patient_id
 * @property int $prescriber_id
 * @property int|null $viewer_id
 * @property string $action
 * @property Carbon $created_at
 */
class PrescriptionPatientAccessLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['patient_id', 'prescriber_id', 'viewer_id', 'action'];

    /** @return BelongsTo<PrescriptionPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PrescriptionPatient::class, 'patient_id');
    }

    /** @return BelongsTo<User, $this> */
    public function viewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'viewer_id');
    }
}
