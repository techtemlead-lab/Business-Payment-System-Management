<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One owner-edited SMS or email text. It only overrides the default kept in code.
 */
class MessageTemplate extends Model
{
    protected $fillable = ['key', 'channel', 'locale', 'subject', 'body', 'active', 'updated_by'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    /** @return BelongsTo<User, $this> */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
