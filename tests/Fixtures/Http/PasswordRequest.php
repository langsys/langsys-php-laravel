<?php

namespace Langsys\Laravel\Tests\Fixtures\Http;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** Laravel's own Password rule with every requirement it checks and rules of its own, one requirement worded by the app. */
class PasswordRequest extends FormRequest
{
    public function rules(): array
    {
        return ['pin' => [Password::min(8)->max(12)->letters()->numbers()->symbols()->mixedCase()->uncompromised()->rules(['confirmed', new MaxWords(1), new TranslatedCodeRule()])]];
    }

    public function messages(): array
    {
        return ['pin.password.numbers' => 'Add a number to :attribute.'];
    }
}
