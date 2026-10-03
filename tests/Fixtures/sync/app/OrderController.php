<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

class OrderController
{
    public function show(string $amount, string $dynamic, int $items, array $address)
    {
        return [
            __('messages.welcome', ['name' => 'Ana']),
            __('Pay :amount now', ['amount' => $amount]),
            __($dynamic),
            trans_choice(':count item|:count items', $items),
            __('The :attribute is not a code we issued.'),
            __("messages.$dynamic"),
            __("validation.$dynamic", [], 'en'),
            __('Ship to :city', $address),
        ];
    }
}
