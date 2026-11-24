<?php

namespace Modules\Ortofrutta\Http\Requests;

/**
 * Validation messages in Italian. Core ships no Italian language file, and the
 * screens of this module are in Italian, so the messages are too.
 */
trait ItalianMessages
{
    public function messages(): array
    {
        return [
            'required' => 'Il campo :attribute è obbligatorio.',
            'present' => 'Il campo :attribute è obbligatorio.',
            'numeric' => 'Il campo :attribute deve essere un numero.',
            'integer' => 'Il campo :attribute deve essere un numero intero.',
            'array' => 'Il campo :attribute non è valido.',
            'string' => 'Il campo :attribute deve essere un testo.',
            'exists' => 'Il valore scelto per :attribute non esiste.',
            'distinct' => 'Lo stesso :attribute compare più di una volta.',
            'date_format' => 'Il campo :attribute deve essere un orario nel formato HH:MM.',
            'gt.numeric' => 'Il campo :attribute deve essere maggiore di :value.',
            'lt.numeric' => 'Il campo :attribute deve essere minore di :value.',
            'lte.numeric' => 'Il campo :attribute non può superare :value.',
            'min.numeric' => 'Il campo :attribute deve essere almeno :min.',
            'max.numeric' => 'Il campo :attribute non può superare :max.',
            'max.string' => 'Il campo :attribute non può superare :max caratteri.',
            'max.array' => 'Il campo :attribute non può avere più di :max righe.',
            'lots.*.kg_packed.lte' => 'I kg confezionati non possono superare i kg entrati.',
            'lots.*.kg_waste_cider.lte' => 'Lo scarto sidro non può superare i kg entrati.',
            'lots.*.kg_waste_industry.lte' => 'Lo scarto industria non può superare i kg entrati.',
        ];
    }
}
