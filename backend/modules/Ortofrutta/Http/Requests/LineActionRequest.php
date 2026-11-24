<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LineActionRequest extends FormRequest
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
            'line_id' => ['required', 'integer', 'exists:orto_lines,id'],
        ];
    }

    public function attributes(): array
    {
        return [
            'line_id' => 'linea',
        ];
    }
}
