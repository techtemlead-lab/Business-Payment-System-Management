<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CommissionRuleParameter extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'percentage' => 'decimal:2',
        'amount_threshold' => 'decimal:2',
        'cap_limit' => 'decimal:2',
        'parameters' => 'array',
        'is_active' => 'boolean',
        'version' => 'integer',
    ];

    public function updatedBy()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Get rule value by key with fallback default.
     */
    public static function getVal(string $key, float $default = 0.0): float
    {
        $rule = static::where('rule_key', $key)->where('is_active', true)->first();
        return $rule ? (float) ($rule->percentage > 0 ? $rule->percentage : $rule->amount_threshold) : $default;
    }

    /**
     * Get full rule record.
     */
    public static function getRule(string $key): ?self
    {
        return static::where('rule_key', $key)->first();
    }
}
