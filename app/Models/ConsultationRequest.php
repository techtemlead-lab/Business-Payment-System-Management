<?php

namespace App\Models;

use Database\Factories\ConsultationRequestFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A visitor's request to one listed prescriber. Like a patient, only that prescriber can see
 * it, not even the owner.
 *
 * @property int $id
 * @property int $prescriber_id
 * @property string $name
 * @property string $phone
 * @property string $problem
 * @property string|null $preferred_time
 * @property string $consent_statement
 * @property Carbon $consented_at
 * @property string $status
 * @property int|null $patient_id
 * @property Carbon|null $handled_at
 */
class ConsultationRequest extends Model
{
    /** @use HasFactory<ConsultationRequestFactory> */
    use HasFactory;

    public const StatusNew = 'new';

    public const StatusAccepted = 'accepted';

    public const StatusDeclined = 'declined';

    protected $fillable = ['prescriber_id', 'name', 'phone', 'problem', 'preferred_time', 'consent_statement', 'consented_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['phone' => 'encrypted', 'problem' => 'encrypted', 'consented_at' => 'datetime', 'handled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }

    /** @return BelongsTo<PrescriptionPatient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(PrescriptionPatient::class, 'patient_id');
    }

    /**
     * Requests the viewer may see: only their own.
     *
     * @param  Builder<ConsultationRequest>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where('prescriber_id', $viewer->id);
    }
}
