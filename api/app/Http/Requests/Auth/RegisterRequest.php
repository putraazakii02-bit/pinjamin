<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'nim' => [
                'required',
                'string',
                'max:30',
                Rule::unique('student_profiles', 'nim')
                    ->where(fn (Builder $query) => $query->where(
                        'university_name',
                        $this->input('university_name')
                    )),
            ],
            'university_name' => ['required', 'string', 'max:150'],
            'study_program' => ['required', 'string', 'max:100'],
            'whatsapp_number' => ['required', 'string', 'max:20'],
        ];
    }
}
