<?php

namespace App\Http\Resources\Api\V1;

use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'number' => $this->number, 'user_id' => $this->user_id,
            'status' => $this->status, 'currency' => 'BDT',
            'total' => $this->total, 'paid' => $this->paid,
            'outstanding' => Money::decimal(Money::cents($this->total) - Money::cents($this->paid)),
            'note' => $this->note, 'decision_reason' => $this->decision_reason,
            'created_at' => $this->created_at?->toISOString(), 'updated_at' => $this->updated_at?->toISOString(),
            'lines' => $this->whenLoaded('lines', fn () => $this->lines->map(fn ($line) => [
                'id' => $line->id, 'product_id' => $line->product_id, 'name' => $line->name, 'sku' => $line->sku,
                'quantity' => (int) $line->quantity, 'dispatched' => (int) $line->dispatched,
                'remaining' => (int) $line->quantity - (int) $line->dispatched,
                'mrp' => Money::decimal(Money::cents((string) $line->mrp)),
                'unit_price' => Money::decimal(Money::cents((string) $line->unit_price)),
                'total' => Money::decimal(Money::cents((string) $line->total)),
            ])),
        ];
    }
}
