<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/** A request that declares the message of its rule object, which Laravel picks over the rule's own. */
class DeclaredCodeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => [new TranslatedCodeRule()]];
    }

    public function messages(): array
    {
        return ['code.' . TranslatedCodeRule::class => 'That code is wrong.'];
    }
}
