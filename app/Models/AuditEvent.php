<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit records are append-only.'));
        static::deleting(fn () => throw new \LogicException('Audit records are append-only.'));
    }

    public static function record(string $action, array $details = [], ?int $subject = null): self
    {
        return static::create(['actor_id' => auth()->id(), 'action' => $action, 'subject_id' => $subject, 'details' => $details, 'created_at' => now()]);
    }
}
