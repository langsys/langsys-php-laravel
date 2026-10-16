<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

/** Laravel's File rule, which states its message only once it has failed, once with a message the app declared for it. */
class UploadRequest extends FormRequest
{
    public function rules(): array
    {
        return ['doc' => [File::types(['pdf'])], 'scan' => [File::types(['pdf'])]];
    }

    public function messages(): array
    {
        return ['scan.' . File::class => 'Upload the scan as a PDF.'];
    }
}
