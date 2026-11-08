<?php

namespace App\Models;

use Database\Factories\PrescriberPublicProfileFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A prescriber's own choice to appear on the public directory. Off by default.
 *
 * @property int $id
 * @property int $user_id
 * @property bool $listed
 * @property string|null $chamber_hours
 */
class PrescriberPublicProfile extends Model
{
    /** @use HasFactory<PrescriberPublicProfileFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'listed', 'chamber_hours'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['listed' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
