<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;
use Langsys\Laravel\Tests\Messages\Fixtures\Explodes;
use Langsys\Laravel\Tests\Messages\Fixtures\Uppercase;

class UnlistableRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'broken'      => [new Explodes()],
            'code'        => [new Uppercase()],
            'lines.*.qty' => 'required|integer',
            'note'        => 'required',
            'contact'     => 'phone_number',
        ];
    }

    public function messages(): array
    {
        return ['note.required' => 'Write a :thing here.'];
    }
}
