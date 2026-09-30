<?php

namespace Langsys\Laravel\Tests\Fixtures\sync\app;

class OrderController
{
    public function show(string $amount, string $dynamic, int $items)
    {
        return [
            __('messages.welcome', ['name' => 'Ana']),
            __('Pay :amount now', ['amount' => $amount]),
            __($dynamic),
            trans_choice(':count item|:count items', $items),
        ];
    }
}
