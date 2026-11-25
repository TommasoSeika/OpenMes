<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Who is holding the terminal, and which mission is open on it. */
class TerminalRequest extends FormRequest
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
            'persona' => ['nullable', 'integer', 'exists:orto_people,id'],
            'missione' => ['nullable', 'integer', 'exists:orto_missions,id'],
        ];
    }

    public function attributes(): array
    {
        return ['persona' => 'persona', 'missione' => 'missione'];
    }
}
