<?php

namespace App\Http\Requests;

use App\Enums\ContactStatus;
use App\Models\Contact;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListContactsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Contact::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::enum(ContactStatus::class)],
            'assigned_agent_id' => ['nullable', 'integer'],
            'unassigned' => ['nullable', 'boolean'],
            // Only meaningful for a team lead; ignored for an agent (their
            // queries are always scoped to their own contacts regardless).
            'archived' => ['nullable', Rule::in(['without', 'only', 'with'])],
            'sort' => ['nullable', Rule::in(['created_at', 'last_activity_at'])],
            'direction' => ['nullable', Rule::in(['asc', 'desc'])],
            'cursor' => ['nullable', 'string'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
