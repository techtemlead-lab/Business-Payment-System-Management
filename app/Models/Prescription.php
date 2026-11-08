<?php

namespace App\Models;

use Database\Factories\PrescriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A prescription. `partner` prescriptions (dealers and sub-dealers) carry typed medicines
 * and prices only. `clinic` prescriptions (the owner-doctor) pick company products and can
 * be dispensed once from a branch.
 *
 * @property int $id
 * @property string $number
 * @property string $kind
 * @property int $prescriber_id
 * @property int $patient_id
 * @property Carbon $prescribed_on
 * @property string|null $complaints
 * @property string|null $diagnosis
 * @property string|null $advice
 * @property Carbon|null $follow_up_on
 * @property string $subtotal
 * @property string $discount_total
 * @property string $total
 * @property string $status
 * @property int|null $branch_id
 * @property string $mrp_total
 * @property string $manufacturer_share
 * @property string $clinic_fund
 * @property int|null $dispensed_by
 * @property Carbon|null $dispensed_at
 * @property string $payment_method
 * @property string|null $payment_reference
 */
class Prescription extends Model
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    public const KindPartner = 'partner';

    public const KindClinic = 'clinic';

    public const StatusIssued = 'issued';

    public const StatusDispensed = 'dispensed';

    public const PaymentCash = 'cash';

    /** How a clinic sale can be paid; cash is the default. */
    public const PaymentMethods = [self::PaymentCash, 'bkash', 'nagad', 'bank', 'other'];

    protected $fillable = [
        'number', 'kind', 'prescriber_id', 'patient_id', 'prescribed_on', 'complaints', 'diagnosis', 'advice', 'follow_up_on',
        'subtotal', 'discount_total', 'total', 'status', 'branch_id', 'mrp_total', 'manufacturer_share', 'clinic_fund',
        'dispensed_by', 'dispensed_at', 'payment_method', 'payment_reference',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'complaints' => 'encrypted',
            'diagnosis' => 'encrypted',
            'advice' => 'encrypted',
            'prescribed_on' => 'date',
            'follow_up_on' => 'date',
            'dispensed_at' => 'datetime',
            'subtotal' => 'decimal:2',
            'discount_total' => 'decimal:2',
            'total' => 'decimal:2',
            'mrp_total' => 'decimal:2',
            'manufacturer_share' => 'decimal:2',
            'clinic_fund' => 'decimal:2',
        ];
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

    /** @return BelongsTo<CompanyBranch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(CompanyBranch::class, 'branch_id');
    }

    /** @return HasMany<PrescriptionLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PrescriptionLine::class)->orderBy('position');
    }
}
