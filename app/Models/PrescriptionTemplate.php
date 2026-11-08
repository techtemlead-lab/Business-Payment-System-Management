<?php

namespace App\Models;

use Database\Factories\PrescriptionTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A named set of medicines and advice a prescriber can start a new prescription from.
 * Only the prescriber who saved it can see or use it, including for the owner-doctor.
 * A template is only a pre-fill for the form: it holds no money and moves no stock.
 *
 * @property int $id
 * @property int $prescriber_id
 * @property string $name
 * @property string|null $advice
 * @property list<array{product_id: int|null, medicine_name: string, dose: string|null, frequency: string|null, duration: string|null, instructions: string|null, unit_price: string, quantity: int, discount: string}> $lines
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PrescriptionTemplate extends Model
{
    /** @use HasFactory<PrescriptionTemplateFactory> */
    use HasFactory;

    protected $fillable = ['prescriber_id', 'name', 'advice', 'lines'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['lines' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function prescriber(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prescriber_id');
    }
}
