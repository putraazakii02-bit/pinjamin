<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;

class ReportPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $proofRequired = in_array(
            $this->input('payment_method'),
            ['bank_transfer', 'other_manual'],
            true
        );

        return [
            'payment_method' => [
                'required',
                Rule::in(['cash', 'bank_transfer', 'other_manual']),
            ],
            'payment_note' => ['nullable', 'string'],
            'proof' => [
                $proofRequired ? 'required' : 'nullable',
                'file',
                File::types(['jpg', 'jpeg', 'png', 'pdf'])->max(5120),
            ],
        ];
    }
}
