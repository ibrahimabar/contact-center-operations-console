<?php

namespace App\Http\Requests;

use App\Enums\ContactStatus;
use App\Enums\UserRole;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Contact::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['required', 'string', 'max:30', 'regex:/^[0-9+\-()\s]+$/'],
            'company' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            // Only honored for a team lead (an agent's contacts are always
            // self-assigned) — see DECISIONS.md "Domain rules". Validated
            // here regardless, so an invalid/inactive/non-agent id is
            // rejected before it ever reaches the service layer.
            'assigned_agent_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->where('role', UserRole::Agent->value)->where('is_active', true),
            ],
        ];
    }
}
