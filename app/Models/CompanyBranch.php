<?php

namespace App\Models;

use Database\Factories\CompanyBranchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CompanyBranch extends Model
{
    /** @use HasFactory<CompanyBranchFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected $attributes = [
        'is_active' => true,
        'is_delivery_point' => true,
        'is_stock_hub' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_delivery_point' => 'boolean',
            'is_stock_hub' => 'boolean',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
        ];
    }

    public function placeCode(): BelongsTo
    {
        return $this->belongsTo(PlaceCode::class);
    }

    public function stocks(): HasMany
    {
        return $this->hasMany(BranchProductStock::class, 'branch_id');
    }

    public function staffAssignments(): HasMany
    {
        return $this->hasMany(BranchStaffAssignment::class, 'branch_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDeliveryPoints($query)
    {
        return $query->active()->where('is_delivery_point', true);
    }

    /**
     * @return array{
     *   id:int,code:string,name:string,division:?string,district:?string,upazila:?string,
     *   address:?string,phone:?string,is_active:bool,is_delivery_point:bool,is_stock_hub:bool,
     *   latitude:?string,longitude:?string,place_code_id:?int
     * }
     */
    public function summary(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'division' => $this->division,
            'district' => $this->district,
            'upazila' => $this->upazila,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'is_delivery_point' => (bool) $this->is_delivery_point,
            'is_stock_hub' => (bool) $this->is_stock_hub,
            'latitude' => $this->latitude !== null ? (string) $this->latitude : null,
            'longitude' => $this->longitude !== null ? (string) $this->longitude : null,
            'place_code_id' => $this->place_code_id,
        ];
    }
}
