<?php

namespace App\Models;

use Database\Factories\PrescriptionPatientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A patient of one prescriber. Only that prescriber can see the record, not even the owner.
 *
 * @property int $id
 * @property int $prescriber_id
 * @property string $name
 * @property int|null $age
 * @property string|null $sex
 * @property string|null $phone
 * @property int|null $place_code_id
 * @property string|null $notes
 * @property string|null $allergies
 */
class PrescriptionPatient extends Model
{
    /** @use HasFactory<PrescriptionPatientFactory> */
    use HasFactory;

    public const Sexes = ['male', 'female', 'other'];

    protected $fillable = ['prescriber_id', 'name', 'age', 'sex', 'phone', 'place_code_id', 'notes', 'allergies'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['age' => 'integer', 'allergies' => 'encrypted'];
    }

    /** @return BelongsTo<User, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }

    /** @return BelongsTo<PlaceCode, $this> */
    public function placeCode(): BelongsTo
    {
        return $this->belongsTo(PlaceCode::class);
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class, 'patient_id');
    }

    /** @return HasOne<PrescriptionPatientConsent, $this> */
    public function consent(): HasOne
    {
        return $this->hasOne(PrescriptionPatientConsent::class, 'patient_id');
    }

    /**
     * Patients the viewer may see: only their own.
     *
     * @param  Builder<PrescriptionPatient>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        $query->where('prescriber_id', $viewer->id);
    }
}
