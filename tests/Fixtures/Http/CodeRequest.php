<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/** A request whose rule object reads its message through `__()`, for the listing's language. */
class CodeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['code' => [new TranslatedCodeRule()]];
    }
}
