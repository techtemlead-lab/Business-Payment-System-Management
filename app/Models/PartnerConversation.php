<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The one chat a dealer or sub-dealer has with the company office.
 */
class PartnerConversation extends Model
{
    protected $fillable = ['partner_id', 'last_message_at', 'last_message_preview', 'last_sender_side', 'partner_unread', 'office_unread'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'partner_unread' => 'integer',
            'office_unread' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function partner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'partner_id');
    }

    /** @return HasMany<PartnerMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(PartnerMessage::class, 'conversation_id');
    }
}
