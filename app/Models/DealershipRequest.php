<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class DealershipRequest extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'status', 'reviewed_by', 'reviewed_at', 'review_note'];

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }
}
