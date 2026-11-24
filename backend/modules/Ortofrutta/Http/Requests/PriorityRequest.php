<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PriorityRequest extends FormRequest
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
            'priority' => ['required', 'integer', 'min:1', 'max:3'],
        ];
    }

    public function attributes(): array
    {
        return [
            'priority' => 'priorità',
        ];
    }
}
