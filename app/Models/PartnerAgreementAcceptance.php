<?php

namespace App\Models;

use Database\Factories\PartnerAgreementAcceptanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A partner pressing "I have read and agree" on one agreement version, with
 * the time, IP address and device they used.
 */
class PartnerAgreementAcceptance extends Model
{
    /** @use HasFactory<PartnerAgreementAcceptanceFactory> */
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<PartnerAgreementVersion, $this> */
    public function agreementVersion(): BelongsTo
    {
        return $this->belongsTo(PartnerAgreementVersion::class, 'partner_agreement_version_id');
    }
}
