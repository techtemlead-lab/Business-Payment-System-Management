<?php

namespace App\Models;

use Database\Factories\PrescriptionDietTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named list of foods to eat and foods to avoid that a prescriber adds to a prescription.
 * Only the prescriber who saved it can see or use it, including for the owner-doctor.
 *
 * @property int $id
 * @property int $prescriber_id
 * @property string $name
 * @property list<string>|null $foods_allowed
 * @property list<string>|null $foods_avoided
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrescriptionDietTemplate extends Model
{
    /** @use HasFactory<PrescriptionDietTemplateFactory> */
    use HasFactory;

    protected $fillable = ['prescriber_id', 'name', 'foods_allowed', 'foods_avoided'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['foods_allowed' => 'encrypted:array', 'foods_avoided' => 'encrypted:array'];
    }

    /** @return BelongsTo<User, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }
}
