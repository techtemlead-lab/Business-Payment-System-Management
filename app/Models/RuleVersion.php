<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RuleVersion extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Rule versions are immutable. Create a new version.'));
        static::deleting(fn () => throw new \LogicException('Historical rules cannot be deleted.'));
    }
}
