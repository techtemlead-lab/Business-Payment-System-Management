<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One message in a partner's chat with the office, written by the partner or by office staff.
 */
class PartnerMessage extends Model
{
    public const SidePartner = 'partner';

    public const SideOffice = 'office';

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'sender_id', 'sender_side', 'body', 'attachment_path', 'attachment_mime'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<PartnerConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(PartnerConversation::class, 'conversation_id');
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
