<?php

namespace App\Http\Requests\Rental;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreRentalRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'request_message' => ['nullable', 'string'],
            'proposed_daily_price' => [
                'required',
                'numeric',
                'gt:0',
                'decimal:0,2',
                'max:9999999999.99',
            ],
            'proposed_start_at' => ['required', 'string', 'date'],
            'proposed_due_at' => ['required', 'string', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->has('proposed_start_at')
                || $validator->errors()->has('proposed_due_at')) {
                return;
            }

            try {
                $start = $this->normalizeDateTime($this->validated('proposed_start_at'));
                $due = $this->normalizeDateTime($this->validated('proposed_due_at'));
            } catch (\InvalidArgumentException) {
                $validator->errors()->add(
                    'proposed_start_at',
                    'Waktu peminjaman harus berisi tanggal dan waktu yang valid.'
                );

                return;
            }

            $startMicroseconds = ($start->getTimestamp() * 1_000_000) + $start->micro;
            $dueMicroseconds = ($due->getTimestamp() * 1_000_000) + $due->micro;

            if ($dueMicroseconds <= $startMicroseconds) {
                $validator->errors()->add(
                    'proposed_due_at',
                    'Waktu pengembalian harus lebih besar daripada waktu mulai.'
                );

                return;
            }

            if (($dueMicroseconds - $startMicroseconds) < 86_400_000_000) {
                $validator->errors()->add(
                    'proposed_due_at',
                    'Durasi peminjaman minimal 24 jam penuh.'
                );
            }
        });
    }

    public function normalizedProposedStartAt(): CarbonImmutable
    {
        return $this->normalizeDateTime($this->validated('proposed_start_at'));
    }

    public function normalizedProposedDueAt(): CarbonImmutable
    {
        return $this->normalizeDateTime($this->validated('proposed_due_at'));
    }

    private function normalizeDateTime(string $value): CarbonImmutable
    {
        $timezone = config('app.timezone');

        return CarbonImmutable::parse($value, $timezone)->setTimezone($timezone);
    }
}
