<?php

namespace Langsys\Laravel\Tests\Fixtures\SyncWatch;

use Illuminate\Foundation\Http\FormRequest;

class NewsletterRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => 'required'];
    }
}
