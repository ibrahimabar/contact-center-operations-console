<?php

namespace App\Http\Requests;

use App\Enums\ContactStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateContactRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('contact'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => ['sometimes', 'required', 'email', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:30', 'regex:/^[0-9+\-()\s]+$/'],
            'company' => ['sometimes', 'nullable', 'string', 'max:255'],
            'status' => ['sometimes', 'required', Rule::enum(ContactStatus::class)],

            // Reassignment only ever happens through POST .../assign, which
            // also carries its own version check — see DECISIONS.md #3 and
            // "Domain rules". Rejecting it here (rather than silently
            // ignoring it) surfaces a client bug immediately instead of
            // masking it.
            'assigned_agent_id' => ['prohibited'],

            // Optimistic-locking token the client must have last read —
            // see DECISIONS.md #3.
            'version' => ['required', 'integer', 'min:1'],
        ];
    }
}
