<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/** Labels set in `withValidator()`, where Laravel lets a request configure its validator. */
class HookedRequest extends FormRequest
{
    public function rules(): array
    {
        return ['plan' => 'required'];
    }

    public function withValidator($validator): void
    {
        $validator->setAttributeNames(['plan' => 'billing plan']);
    }
}
