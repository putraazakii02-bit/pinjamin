<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalNegotiationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'proposed_daily_price' => $this->proposed_daily_price,
            'proposed_start_at' => $this->proposed_start_at,
            'proposed_due_at' => $this->proposed_due_at,
            'message' => $this->message,
            'status' => $this->status,
        ];
    }
}
