<?php

return [
    'required'    => 'El campo :attribute es obligatorio.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'min'         => ['string' => 'El campo :attribute debe tener al menos :min caracteres.'],
    'enum'        => 'El :attribute seleccionado no es válido.',
    'password'    => ['letters' => 'El campo :attribute debe contener al menos una letra.'],
    'attributes'  => ['email' => 'correo electrónico', 'type' => 'tipo', 'notes' => 'notas'],
    'values'      => ['type' => ['business' => 'empresa']],
];
