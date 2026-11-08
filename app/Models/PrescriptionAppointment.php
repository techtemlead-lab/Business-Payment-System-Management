<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One patient's place in a prescriber's queue for a day. Only that prescriber can see it.
 *
 * @property int $id
 * @property int $prescriber_id
 * @property int $patient_id
 * @property Carbon $appointment_on
 * @property string|null $appointment_time
 * @property int $token
 * @property string $status
 * @property string|null $note
 */
class PrescriptionAppointment extends Model
{
    public const StatusWaiting = 'waiting';

    public const StatusServing = 'serving';

    public const StatusDone = 'done';

    public const StatusCancelled = 'cancelled';

    protected $fillable = ['prescriber_id', 'patient_id', 'appointment_on', 'appointment_time', 'token', 'status', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['appointment_on' => 'date', 'token' => 'integer', 'note' => 'encrypted'];
    }

    /** @return BelongsTo<PrescriptionPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PrescriptionPatient::class, 'patient_id');
    }
}
