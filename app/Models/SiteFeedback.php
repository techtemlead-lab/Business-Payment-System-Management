<?php

namespace App\Models;

use Database\Factories\SiteFeedbackFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteFeedback extends Model
{
    /** @use HasFactory<SiteFeedbackFactory> */
    use HasFactory;

    protected $table = 'site_feedback';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'rating' => 'integer',
            'moderated_at' => 'datetime',
        ];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }
}
