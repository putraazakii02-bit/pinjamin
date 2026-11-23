<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'request_id' => $this->request_id,
            'accepted_negotiation_id' => $this->accepted_negotiation_id,
            'item_id' => $this->item_id,
            'owner_id' => $this->owner_id,
            'borrower_id' => $this->borrower_id,
            'agreed_daily_price' => $this->agreed_daily_price,
            'total_price' => $this->total_price,
            'start_at' => $this->start_at,
            'due_at' => $this->due_at,
            'owner_handover_confirmed_at' => $this->owner_handover_confirmed_at,
            'borrower_received_confirmed_at' => $this->borrower_received_confirmed_at,
            'status' => $this->status,
        ];
    }
}
