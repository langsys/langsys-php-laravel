<?php

namespace Langsys\Laravel\Tests\Fixtures\InlineValidation;

use Illuminate\Support\Facades\Validator;
use Livewire\Component;

/** Livewire shows its own validation through `$errors`: nothing it validates reaches a client as entries. */
class CheckoutComponent extends Component
{
    public string $email = '';

    public function save(): void
    {
        $this->validate(['email' => 'required|email']);
        Validator::make(['email' => $this->email], ['email' => 'email'])->validate();
    }

    public function render(): string
    {
        return '<div></div>';
    }
}
