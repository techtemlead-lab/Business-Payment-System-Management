<?php

namespace App\Models;

use Database\Factories\PrescriberRegistrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A partner's (or the owner-doctor's) practitioner registration, checked by the owner
 * offline. Prescribing is on for a partner only while `enabled` is true.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $enabled
 * @property string $registration_type
 * @property string $registration_number
 * @property string|null $note
 * @property int|null $enabled_by
 * @property Carbon|null $enabled_at
 * @property int|null $disabled_by
 * @property Carbon|null $disabled_at
 * @property string|null $disable_reason
 */
class PrescriberRegistration extends Model
{
    /** @use HasFactory<PrescriberRegistrationFactory> */
    use HasFactory;

    /** Bangladesh registers: BMDC (allopathic), and the Unani and Ayurvedic boards. */
    public const Types = ['bmdc', 'unani', 'ayurvedic'];

    protected $fillable = [
        'user_id', 'enabled', 'registration_type', 'registration_number', 'note',
        'enabled_by', 'enabled_at', 'disabled_by', 'disabled_at', 'disable_reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'enabled_at' => 'datetime',
            'disabled_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function enabler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }
}
