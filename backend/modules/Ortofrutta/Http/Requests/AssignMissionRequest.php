<?php

namespace Modules\Ortofrutta\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Give a mission to another forklift driver. */
class AssignMissionRequest extends FormRequest
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
            'person_id' => ['required', 'integer', 'exists:orto_people,id'],
        ];
    }

    public function attributes(): array
    {
        return ['person_id' => 'carrellista'];
    }
}
