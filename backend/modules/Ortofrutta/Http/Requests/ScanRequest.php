<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** A code read on the terminal for a mission. */
class ScanRequest extends FormRequest
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
            'mission_id' => ['required', 'integer', 'exists:orto_missions,id'],
            'code' => ['required', 'string', 'max:60'],
        ];
    }

    public function attributes(): array
    {
        return ['mission_id' => 'missione', 'code' => 'codice'];
    }
}
