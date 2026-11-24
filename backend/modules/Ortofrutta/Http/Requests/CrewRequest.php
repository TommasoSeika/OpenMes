<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CrewRequest extends FormRequest
{
    use ItalianMessages;

    /** Access is decided by the route middleware (tab.access). */
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'line_id' => ['nullable', 'integer', 'exists:orto_lines,id'],
            'person_id' => ['required', 'integer', 'exists:orto_people,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'line_id' => 'linea',
            'person_id' => 'persona',
        ];
    }
}
