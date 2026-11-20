<?php

namespace App\Http\Requests\Item;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, ValidationRule|string>|string>
     */
    public function rules(): array
    {
        return [
            'category_id' => [
                'required',
                'integer',
                Rule::exists('categories', 'id')
                    ->where(fn (Builder $query) => $query->where('is_active', true)),
            ],
            'title' => ['required', 'string', 'max:150'],
            'description' => ['required', 'string'],
            'daily_price' => ['required', 'numeric', 'gt:0', 'decimal:0,2', 'max:9999999999.99'],
            'item_condition' => ['required', Rule::in(['new', 'like_new', 'good', 'fair'])],
            'condition_notes' => ['nullable', 'string'],
        ];
    }
}
