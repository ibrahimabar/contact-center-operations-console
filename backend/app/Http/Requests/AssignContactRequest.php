<?php

namespace App\Http\Requests;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('assign', $this->route('contact'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'assigned_agent_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Agent->value)->where('is_active', true),
            ],
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
