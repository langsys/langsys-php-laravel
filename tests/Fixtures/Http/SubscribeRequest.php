<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;

/** A FormRequest a closure route takes. */
class SubscribeRequest extends FormRequest
{
    public function rules(): array
    {
        return ['topic' => 'required'];
    }
}
