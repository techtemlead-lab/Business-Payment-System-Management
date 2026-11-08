<?php

namespace App\Models;

use Database\Factories\PrivacyRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A partner asking for a copy of their data or for it to be deleted. It holds no free text
 * from the partner, so a patient's details can never end up in the owner's queue.
 *
 * @property int $id
 * @property int $user_id
 * @property string $kind
 * @property string $status
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 */
class PrivacyRequest extends Model
{
    /** @use HasFactory<PrivacyRequestFactory> */
    use HasFactory;

    public const KindAccess = 'data_access';

    public const KindDelete = 'data_delete';

    public const Kinds = [self::KindAccess, self::KindDelete];

    public const StatusOpen = 'open';

    public const StatusInProgress = 'in_progress';

    public const StatusDone = 'done';

    public const StatusRejected = 'rejected';

    /** Statuses the owner can move a request to. */
    public const ResolutionStatuses = [self::StatusInProgress, self::StatusDone, self::StatusRejected];

    /** Statuses still waiting for the owner. */
    public const PendingStatuses = [self::StatusOpen, self::StatusInProgress];

    protected $fillable = ['user_id', 'kind'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
