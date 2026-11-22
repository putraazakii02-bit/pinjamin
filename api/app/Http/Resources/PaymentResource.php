<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'transaction_id' => $this->transaction_id,
            'reported_by' => $this->reported_by,
            'payment_method' => $this->payment_method,
            'payment_type' => $this->payment_type,
            'amount' => $this->amount,
            'payment_note' => $this->payment_note,
            'payment_status' => $this->payment_status,
            'reported_at' => $this->reported_at,
            'confirmed_by' => $this->confirmed_by,
            'confirmed_at' => $this->confirmed_at,
            'rejected_by' => $this->rejected_by,
            'rejected_at' => $this->rejected_at,
            'rejection_note' => $this->rejection_note,
            'has_proof' => (bool) $this->proof_path,
            'proof_url' => $this->proof_path
                ? route(
                    'api.payments.proof',
                    [
                        'transaction' => $this->transaction_id,
                        'payment' => $this->id,
                    ],
                    true
                )
                : null,
        ];
    }
}
