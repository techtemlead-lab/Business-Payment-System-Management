<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One prescriber's letterhead text and optional own logo. Read and written only through
 * App\Domain\Clinic\PrescriptionLetterhead.
 *
 * @property int $id
 * @property int $user_id
 * @property string|null $logo_path
 */
class PrescriptionLetterhead extends Model
{
    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
