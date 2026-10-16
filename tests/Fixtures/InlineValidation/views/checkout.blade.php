<?php

use Illuminate\Support\Facades\Validator;
use Livewire\Volt\Component;

new class extends Component {
    public string $email = '';

    public function save(): void
    {
        Validator::make(['email' => $this->email], ['email' => 'required|email'])->validate();
    }
};
?>

<form wire:submit="save"><input wire:model="email"></form>
