<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RentalExtensionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'proposer_id' => $this->proposer_id,
            'previous_extension_id' => $this->previous_extension_id,
            'previous_due_at' => $this->previous_due_at,
            'proposed_due_at' => $this->proposed_due_at,
            'proposed_fee' => $this->proposed_fee,
            'message' => $this->message,
            'status' => $this->status,
            'responded_by' => $this->responded_by,
            'responded_at' => $this->responded_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
